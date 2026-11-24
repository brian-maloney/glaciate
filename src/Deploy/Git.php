<?php
/**
 * Deploys to a branch of a Git repository over HTTPS.
 *
 * @package Glaciate
 */

namespace Glaciate\Deploy;

defined( 'ABSPATH' ) || exit;

use Glaciate\Deploy\Aws\RequestsTransport;
use Glaciate\Deploy\Aws\Transport;
use Glaciate\Deploy\Git\Objects;
use Glaciate\Deploy\Git\Pack;
use Glaciate\Deploy\Git\RemoteClient;
use Glaciate\Deploy\Git\ReservedPaths;
use Glaciate\Deploy\Git\UploadBranch;
use Glaciate\Output\PathMapper;
use Glaciate\Output\UnsafePathException;
use Glaciate\Security\TargetPolicy;
use Glaciate\Support\Logger;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Reads files from the build cache (see DECISIONS.md D9).

/**
 * Commits the build to one branch that Glaciate owns, for hosts that publish from Git (GitHub Pages, GitLab
 * Pages, Codeberg Pages, or any CI). Pure PHP: objects and packs are built here and pushed over Git's smart-HTTP
 * protocol with a token, so no `git` binary or `proc_open` is needed (DECISIONS.md D15).
 *
 * Each deploy makes one commit whose tree is the whole site, with the branch's current head as parent: pushes are
 * always fast-forwards and history is kept. The branch must be new or have been written by this deployer; a branch
 * with commits from elsewhere is refused unless "Replace the branch's contents" is turned on (threat T5).
 *
 * Only new and changed blobs are sent. A deploy larger than one batch uploads its blobs in batches to a temporary
 * branch (one commit per batch, so the server keeps them), then a final push moves the real branch and deletes the
 * temporary one. Every batch is saved, so a deploy resumes across background slices. A temporary branch is built on
 * or deleted only while it holds a commit this deployer recorded; otherwise a new one gets a fresh name (D65).
 */
final class Git implements ConfigurableDeployerInterface, ResumableDeployerInterface {

	/** Bytes of new file contents per push. */
	const BATCH_BYTES = 16777216;

	/** Largest file accepted (GitHub's limit). */
	const MAX_FILE = 104857600;

	/**
	 * Option holding per-target state: target key => {head, upload_ref, upload_head, upload_next, stale}. upload_next is
	 * an upload commit pushed without an answer yet; stale maps temporary branches the server refused to delete to the
	 * commit we left on them.
	 */
	const STATE_OPTION = 'glaciate_git_state';

	/**
	 * Transport (injected by tests; RequestsTransport otherwise).
	 *
	 * @var Transport|null
	 */
	private $transport;

	/**
	 * Stop starting new work after this time (0 = never).
	 *
	 * @var float
	 */
	private $deadline = 0.0;

	/**
	 * Constructor.
	 *
	 * @param Transport|null $transport Transport; null to use the WordPress HTTP library.
	 */
	public function __construct( ?Transport $transport = null ) {
		$this->transport = $transport;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'git';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'A Git repository (GitHub Pages, GitLab Pages and others)', 'glaciate' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function fields(): array {
		return array(
			'repository_url'   => array(
				'label'       => __( 'Repository address', 'glaciate' ),
				'required'    => true,
				'restricted'  => true,
				'pattern'     => 'https://[A-Za-z0-9.\-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~\-]+)+/?',
				'description' => __( 'The HTTPS clone address, for example https://github.com/example/site.git.', 'glaciate' ),
			),
			'branch'           => array(
				'label'       => __( 'Branch', 'glaciate' ),
				'required'    => true,
				'restricted'  => true,
				'default'     => 'gh-pages',
				'pattern'     => '[A-Za-z0-9._\-/]{1,100}',
				'description' => __( 'Glaciate owns this branch and replaces its files on every publish. Other branches are never touched.', 'glaciate' ),
			),
			'username'         => array(
				'label'       => __( 'User name', 'glaciate' ),
				'default'     => 'x-access-token',
				'pattern'     => '[A-Za-z0-9._@\-]{1,100}',
				'description' => __( 'Most hosts accept any name with a token. GitLab uses "oauth2" for OAuth tokens.', 'glaciate' ),
			),
			'token'            => array(
				'label'       => __( 'Access token', 'glaciate' ),
				'type'        => 'secret',
				'required'    => true,
				'description' => __( 'A token that may push to this repository, for example a fine-grained GitHub token with "Contents: read and write".', 'glaciate' ),
			),
			'author_name'      => array(
				'label'   => __( 'Commit author name', 'glaciate' ),
				'default' => 'Glaciate',
				'pattern' => '[^<>\n\r\x00]{1,100}',
			),
			'author_email'     => array(
				'label'       => __( 'Commit author email', 'glaciate' ),
				'default'     => 'glaciate@invalid',
				'pattern'     => '[^<>\s@]{1,64}@[^<>\s@]{1,255}',
				'description' => __( 'Shown in the public commit history. The default reveals no address.', 'glaciate' ),
			),
			'commit_message'   => array(
				'label'   => __( 'Commit message', 'glaciate' ),
				'default' => 'Publish static site',
				'pattern' => '[^\x00\r\n]{1,200}',
			),
			'replace_existing' => array(
				'label'       => __( 'Replace the branch\'s contents', 'glaciate' ),
				'type'        => 'bool',
				'default'     => false,
				'restricted'  => true,
				'description' => __( 'Allow publishing to a branch that already has commits Glaciate didn\'t make. Its files are replaced in a new commit; its history is kept.', 'glaciate' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function problem(): ?string {
		$missing = TargetConfig::missing( $this );
		if ( $missing ) {
			/* translators: %s: comma-separated field names. */
			return sprintf( __( 'Git needs these settings: %s.', 'glaciate' ), implode( ', ', $missing ) );
		}
		if ( ! function_exists( 'gzcompress' ) ) {
			return __( 'Publishing to Git needs the PHP zlib extension.', 'glaciate' );
		}
		$branch = (string) TargetConfig::value( $this, 'branch' );
		if ( ! self::valid_branch( $branch ) ) {
			return __( 'That branch name is not valid in Git.', 'glaciate' );
		}
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param float $deadline Unix time with microseconds; 0 for no deadline.
	 */
	public function set_deadline( float $deadline ): void {
		$this->deadline = $deadline;
	}

	/**
	 * {@inheritDoc}
	 */
	public function check_connection(): ?string {
		$problem = $this->problem();
		if ( null !== $problem ) {
			return $problem;
		}
		try {
			$info = $this->client()->discover();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		$head  = $info['refs'][ $this->ref() ] ?? '';
		$state = $this->state();
		if ( '' !== $head && $head !== $state['head'] && ! TargetConfig::value( $this, 'replace_existing' ) ) {
			return $this->foreign_branch_message();
		}
		return null;
	}

	/**
	 * Commits the manifest to the branch.
	 *
	 * @param array<string,string> $manifest    Relative path => SHA-256.
	 * @param string               $source_root Build cache directory.
	 * @param Logger               $logger      Build log.
	 * @return DeployResult
	 * @throws \RuntimeException When the settings are incomplete, the branch isn't ours, or the server refuses the push.
	 */
	public function deploy( array $manifest, string $source_root, Logger $logger ): DeployResult {
		$problem = $this->problem();
		if ( null !== $problem ) {
			throw new \RuntimeException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Logged as plain text and escaped on display.
		}
		$manifest = self::without_reserved_paths( $manifest, $logger );

		$client = $this->client();
		$info   = $client->discover();
		$ref    = $this->ref();
		$head   = $info['refs'][ $ref ] ?? '';
		$state  = $this->state();
		$files  = new TargetFiles( 'git-' . $this->target_key() );
		$blobs  = new TargetFiles( 'gitb-' . $this->target_key() );
		$queue  = new TargetFiles( 'gitq-' . $this->target_key() );
		$result = new DeployResult();

		$result->location = TargetConfig::value( $this, 'repository_url' ) . ' (' . TargetConfig::value( $this, 'branch' ) . ')';

		if ( '' === $head || $head !== $state['head'] ) {
			if ( '' !== $head && ! TargetConfig::value( $this, 'replace_existing' ) ) {
				throw new \RuntimeException( $this->foreign_branch_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
			}
			if ( '' !== $state['head'] ) {
				$logger->info( '' === $head ? __( 'The Git branch was deleted; publishing every file again.', 'glaciate' ) : __( 'The Git branch changed outside Glaciate; publishing every file again.', 'glaciate' ) );
			}
			// We can't tell which objects the server has, so forget what we sent and send everything.
			$files->clear();
			$blobs->clear();
			$state['head'] = '';
		}
		// Uploads from an interrupted deploy count only while their temporary branch still holds a commit we recorded.
		// Any other branch of that kind of name may be someone else's: it is never built on or deleted (D65).
		$upload = UploadBranch::choose(
			$state,
			$info['refs'],
			$this->target_key(),
			static function () {
				return bin2hex( random_bytes( 6 ) );
			}
		);
		if ( ! $upload['resume'] ) {
			$queue->clear();
			if ( '' !== $state['upload_ref'] && isset( $info['refs'][ $state['upload_ref'] ] ) ) {
				/* translators: %s: Git branch. */
				$logger->info( sprintf( __( 'The Git server lists the temporary branch %s, but not at a commit Glaciate recorded, so it was left alone. Delete it by hand if nothing else uses it.', 'glaciate' ), $state['upload_ref'] ) );
			}
		}
		$state['upload_ref']  = $upload['upload_ref'];
		$state['upload_head'] = $upload['upload_head'];
		$state['upload_next'] = $upload['upload_next'];

		$held    = $files->all();
		$known   = $blobs->all();
		$queued  = $queue->all();
		$source  = new PathMapper( $source_root );
		$tree    = array();
		$send    = array();
		$changed = array();
		foreach ( $manifest as $path => $hash ) {
			$path = (string) $path;
			if ( isset( $held[ $path ], $known[ $path ] ) && $held[ $path ] === $hash ) {
				$tree[ $path ] = $known[ $path ];
				++$result->unchanged;
				continue;
			}
			try {
				$file = $source->absolute_path( $path );
			} catch ( UnsafePathException $e ) {
				$file = '';
				/* translators: 1: file path, 2: error message. */
				$logger->error( sprintf( __( 'Could not publish %1$s: %2$s', 'glaciate' ), $path, $e->getMessage() ) );
			}
			$size = '' === $file ? false : filesize( $file );
			$blob = false === $size ? null : Objects::blob_id_of_file( $file );
			if ( null === $blob || $size > self::MAX_FILE ) {
				if ( null !== $blob ) {
					/* translators: %s: file path. */
					$logger->error( sprintf( __( 'Could not publish %s: Git hosts refuse files over 100 MB.', 'glaciate' ), $path ) );
				}
				++$result->failed;
				if ( isset( $known[ $path ] ) ) {
					$tree[ $path ] = $known[ $path ];
					// Keep the version that is already published.
				}
				continue;
			}
			$tree[ $path ]    = $blob;
			$changed[ $path ] = (string) $hash;
			if ( ( $queued[ $path ] ?? '' ) !== $blob ) {
				$send[ $path ] = array(
					'file' => $file,
					'blob' => $blob,
					'size' => (int) $size,
				);
			}
		}//end foreach
		$deleted = array_diff_key( $held, $manifest );
		foreach ( self::extras() as $path => $contents ) {
			if ( ! isset( $tree[ $path ] ) ) {
				$tree[ $path ] = Objects::blob_id( $contents );
			}
		}

		if ( '' !== $state['head'] && ! $changed && ! $deleted ) {
			return $result;
			// The branch already holds exactly this site.
		}

		// In an empty repository, hosts such as GitHub make the first branch pushed the default branch, and refuse to
		// delete the default branch. Batches go to a temporary branch, so create the real branch first, with a commit
		// that holds only the marker files; the site's commit follows on top of it.
		if ( '' === $head && ! $info['refs'] && array_sum( array_column( $send, 'size' ) ) > self::BATCH_BYTES ) {
			$head          = $this->prepare_branch( $client, $info['caps'], $ref );
			$state['head'] = $head;
			// Ours: a resumed deploy must not take it for someone else's commit.
			$this->save_state( $state );
		}

		// Upload everything but the last batch to the temporary branch, saving progress after each batch.
		while ( array_sum( array_column( $send, 'size' ) ) > self::BATCH_BYTES ) {
			if ( $this->out_of_time() ) {
				$this->save_state( $state );
				$result->done      = false;
				$result->unchanged = 0;
				return $result;
			}
			$batch = self::take_batch( $send );
			$this->upload_batch( $client, $info['caps'], $batch, $state, $queue );
			$send = array_diff_key( $send, $batch );
		}

		if ( $this->out_of_time() ) {
			$this->save_state( $state );
			$result->done      = false;
			$result->unchanged = 0;
			return $result;
		}

		$root    = Objects::trees( $tree );
		$parents = '' === $head ? array() : array( $head );
		$author  = TargetConfig::value( $this, 'author_name' ) . ' <' . TargetConfig::value( $this, 'author_email' ) . '>';
		$commit  = Objects::commit( $root['root'], $parents, $author, time(), (string) TargetConfig::value( $this, 'commit_message' ) );
		$id      = Objects::id( 'commit', $commit );
		$objects = $this->blob_objects( $send );
		foreach ( self::extras() as $contents ) {
			$objects[] = array( 'blob', $contents );
		}
		foreach ( $root['trees'] as $body ) {
			$objects[] = array( 'tree', $body );
		}
		$objects[] = array( 'commit', $commit );

		$answer = $client->push( array( array( '' === $head ? RemoteClient::ZERO : $head, $id, $ref ) ), Pack::build( $objects ), $info['caps'] );
		unset( $objects );
		if ( 'ok' !== ( $answer[ $ref ] ?? '' ) ) {
			/* translators: 1: branch, 2: reason from the Git server. */
			throw new \RuntimeException( sprintf( __( 'The Git server refused to update %1$s: %2$s', 'glaciate' ), $ref, $answer[ $ref ] ?? __( 'no answer', 'glaciate' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		// Temporary branches go after the site: if the server refuses to delete one, the site is still out. Besides
		// this deploy's own, that includes earlier ones the server kept, while they still hold the commit we left.
		$leftover = array();
		if ( '' !== $state['upload_head'] ) {
			$leftover[ $state['upload_ref'] ] = $state['upload_head'];
		}
		$leftover += UploadBranch::deletable( $state['stale'], $info['refs'] );
		$stale     = $this->delete_upload_branches( $client, $info['caps'], $leftover, $logger );

		foreach ( $changed as $path => $hash ) {
			$files->set( $path, $hash );
			$blobs->set( $path, $tree[ $path ] );
			if ( isset( $held[ $path ] ) ) {
				++$result->modified;
			} else {
				++$result->added;
			}
		}
		foreach ( array_keys( $deleted ) as $path ) {
			$files->remove( (string) $path );
			$blobs->remove( (string) $path );
			++$result->deleted;
		}
		$queue->clear();
		$this->save_state(
			array(
				'head'        => $id,
				'upload_ref'  => '',
				'upload_head' => '',
				'upload_next' => '',
				'stale'       => $stale,
			)
		);
		/* translators: 1: Git commit ID, 2: Git branch. */
		$logger->info( sprintf( __( 'Pushed commit %1$s to %2$s.', 'glaciate' ), substr( $id, 0, 12 ), $ref ) );
		return $result;
	}

	/**
	 * Pushes one batch of blobs to the temporary branch, in a commit whose tree names each blob by its ID (so they
	 * stay reachable and the server keeps them until the final push).
	 *
	 * @param RemoteClient                                          $client Client.
	 * @param string[]                                              $caps   Server capabilities.
	 * @param array<string,array{file:string,blob:string,size:int}> $batch  Files.
	 * @param array<string,mixed>                                   $state  State (updated).
	 * @param TargetFiles                                           $queue  Uploaded-but-unpublished records.
	 * @return void
	 * @throws \RuntimeException When the server refuses the batch.
	 */
	private function upload_batch( RemoteClient $client, array $caps, array $batch, array &$state, TargetFiles $queue ): void {
		$entries = array();
		foreach ( $batch as $item ) {
			$entries[ $item['blob'] ] = array( Objects::FILE, $item['blob'] );
		}
		$tree    = Objects::tree( $entries );
		$parents = '' === $state['upload_head'] ? array() : array( $state['upload_head'] );
		$commit  = Objects::commit( Objects::id( 'tree', $tree ), $parents, 'Glaciate <glaciate@invalid>', time(), 'Upload in progress' );
		$id      = Objects::id( 'commit', $commit );
		$objects = $this->blob_objects( $batch );

		$objects[] = array( 'tree', $tree );
		$objects[] = array( 'commit', $commit );

		// Recorded first: if the answer is lost, the next deploy still knows the commit is ours.
		$state['upload_next'] = $id;
		$this->save_state( $state );

		$old    = '' === $state['upload_head'] ? RemoteClient::ZERO : $state['upload_head'];
		$answer = $client->push( array( array( $old, $id, $state['upload_ref'] ) ), Pack::build( $objects ), $caps );
		if ( 'ok' !== ( $answer[ $state['upload_ref'] ] ?? '' ) ) {
			/* translators: 1: branch, 2: reason from the Git server. */
			throw new \RuntimeException( sprintf( __( 'The Git server refused the upload branch %1$s: %2$s', 'glaciate' ), $state['upload_ref'], $answer[ $state['upload_ref'] ] ?? __( 'no answer', 'glaciate' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		$state['upload_head'] = $id;
		$state['upload_next'] = '';
		$this->save_state( $state );
		foreach ( $batch as $path => $item ) {
			$queue->set( (string) $path, $item['blob'] );
		}
	}

	/**
	 * Deletes temporary upload branches, each in a push of its own that names the commit we left on it as the old ID,
	 * so the server refuses if anyone moved the branch since.
	 *
	 * @param RemoteClient         $client   Client.
	 * @param string[]             $caps     Server capabilities.
	 * @param array<string,string> $branches Ref name => commit ID we recorded on it.
	 * @param Logger               $logger   Build log.
	 * @return array<string,string> The branches the server kept, to try again after the next publish.
	 */
	private function delete_upload_branches( RemoteClient $client, array $caps, array $branches, Logger $logger ): array {
		$kept = array();
		foreach ( $branches as $ref => $head ) {
			$ref = (string) $ref;
			try {
				$gone = $client->push( array( array( $head, RemoteClient::ZERO, $ref ) ), '', $caps );
			} catch ( \RuntimeException $e ) {
				$gone = array();
			}
			if ( 'ok' !== ( $gone[ $ref ] ?? '' ) ) {
				$kept = UploadBranch::remember( $kept, $ref, $head );
				/* translators: 1: branch, 2: reason from the Git server. */
				$logger->warning( sprintf( __( 'Published, but the Git server refused to delete the temporary branch %1$s (%2$s). The next publish tries again, or delete it by hand.', 'glaciate' ), $ref, $gone[ $ref ] ?? __( 'no answer', 'glaciate' ) ) );
			}
		}
		return $kept;
	}

	/**
	 * Creates the branch with a commit holding only the marker files.
	 *
	 * @param RemoteClient $client Client.
	 * @param string[]     $caps   Server capabilities.
	 * @param string       $ref    Branch ref.
	 * @return string Commit ID.
	 * @throws \RuntimeException When the server refuses it.
	 */
	private function prepare_branch( RemoteClient $client, array $caps, string $ref ): string {
		$objects = array();
		$files   = array();
		foreach ( self::extras() as $path => $contents ) {
			$files[ $path ] = Objects::blob_id( $contents );
			$objects[]      = array( 'blob', $contents );
		}
		$root   = Objects::trees( $files );
		$author = TargetConfig::value( $this, 'author_name' ) . ' <' . TargetConfig::value( $this, 'author_email' ) . '>';
		$commit = Objects::commit( $root['root'], array(), $author, time(), 'Prepare branch for Glaciate' );
		foreach ( $root['trees'] as $body ) {
			$objects[] = array( 'tree', $body );
		}
		$objects[] = array( 'commit', $commit );
		$id        = Objects::id( 'commit', $commit );
		$answer    = $client->push( array( array( RemoteClient::ZERO, $id, $ref ) ), Pack::build( $objects ), $caps );
		if ( 'ok' !== ( $answer[ $ref ] ?? '' ) ) {
			/* translators: 1: branch, 2: reason from the Git server. */
			throw new \RuntimeException( sprintf( __( 'The Git server refused to create %1$s: %2$s', 'glaciate' ), $ref, $answer[ $ref ] ?? __( 'no answer', 'glaciate' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		return $id;
	}

	/**
	 * Removes and returns files from the front of the list, up to the batch size (always at least one).
	 *
	 * @param array<string,array{file:string,blob:string,size:int}> $send Files.
	 * @return array<string,array{file:string,blob:string,size:int}>
	 */
	private static function take_batch( array $send ): array {
		$batch = array();
		$bytes = 0;
		foreach ( $send as $path => $item ) {
			if ( $batch && $bytes + $item['size'] > self::BATCH_BYTES ) {
				break;
			}
			$batch[ $path ] = $item;
			$bytes         += $item['size'];
		}
		return $batch;
	}

	/**
	 * Reads files as blob objects for a pack, skipping repeats of the same contents.
	 *
	 * @param array<string,array{file:string,blob:string,size:int}> $items Files.
	 * @return array<int,array{0:string,1:string}>
	 * @throws \RuntimeException When a file can't be read.
	 */
	private function blob_objects( array $items ): array {
		$out  = array();
		$seen = array();
		foreach ( $items as $path => $item ) {
			if ( isset( $seen[ $item['blob'] ] ) ) {
				continue;
			}
			$contents = file_get_contents( $item['file'] );
			if ( false === $contents ) {
				/* translators: %s: file path. */
				throw new \RuntimeException( sprintf( __( 'Could not read %s from the build cache.', 'glaciate' ), $path ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
			}
			$seen[ $item['blob'] ] = true;
			$out[]                 = array( 'blob', $contents );
		}
		return $out;
	}

	/**
	 * Drops manifest paths Git or a Git host would read as repository configuration (ReservedPaths, threat T27).
	 *
	 * Runs before anything else looks at the manifest, so such a path reaches no tree: not the site's commit, not an
	 * upload batch, not a resumed deploy. A path an earlier deploy committed is left out of the new tree and leaves
	 * the records, like any deleted file. The marker files (extras()) are added later and are not affected.
	 *
	 * @param array<string,string> $manifest Relative path => SHA-256.
	 * @param Logger               $logger   Build log.
	 * @return array<string,string>
	 */
	private static function without_reserved_paths( array $manifest, Logger $logger ): array {
		foreach ( array_keys( $manifest ) as $path ) {
			if ( ReservedPaths::is_reserved( (string) $path ) ) {
				unset( $manifest[ $path ] );
				/* translators: %s: file path. */
				$logger->warning( sprintf( __( 'Skipped %s: Git hosts read this name as repository configuration, so Glaciate never commits it.', 'glaciate' ), $path ) );
			}
		}
		return $manifest;
	}

	/**
	 * Files added to every commit: the ownership marker, and .nojekyll so GitHub Pages serves files and folders that
	 * start with an underscore (such as _redirects).
	 *
	 * @return array<string,string>
	 */
	private static function extras(): array {
		return array(
			TargetPolicy::MARKER => "This branch is managed by Glaciate. Each publish replaces its files in a new commit.\n",
			'.nojekyll'          => '',
		);
	}

	/**
	 * Message for a branch with commits from elsewhere.
	 *
	 * @return string
	 */
	private function foreign_branch_message(): string {
		/* translators: %s: branch name. */
		return sprintf( __( 'The branch %s already has commits that Glaciate didn\'t make. Choose a new branch name, or turn on "Replace the branch\'s contents" if this branch may be overwritten.', 'glaciate' ), (string) TargetConfig::value( $this, 'branch' ) );
	}

	/**
	 * True for a branch name Git accepts (the rules of git check-ref-format that the field pattern doesn't cover).
	 *
	 * @param string $branch Branch name.
	 * @return bool
	 */
	public static function valid_branch( string $branch ): bool {
		if ( '' === $branch || '-' === $branch[0] || false !== strpos( $branch, '..' ) || false !== strpos( $branch, '//' ) ) {
			return false;
		}
		if ( '/' === $branch[0] || '/' === substr( $branch, -1 ) || '.' === substr( $branch, -1 ) || '.lock' === substr( $branch, -5 ) ) {
			return false;
		}
		foreach ( explode( '/', $branch ) as $part ) {
			if ( '.' === $part[0] || '.lock' === substr( $part, -5 ) ) {
				return false;
			}
		}
		return 1 === preg_match( '#^[A-Za-z0-9._\-/]+$#', $branch ) && 0 !== strpos( $branch, 'glaciate-upload-' );
	}

	/**
	 * Full ref name of the branch.
	 *
	 * @return string
	 */
	private function ref(): string {
		return 'refs/heads/' . TargetConfig::value( $this, 'branch' );
	}

	/**
	 * State of this repository and branch.
	 *
	 * @return array{head:string,upload_ref:string,upload_head:string,upload_next:string,stale:array<string,string>}
	 */
	private function state(): array {
		$all   = get_option( self::STATE_OPTION, array() );
		$state = is_array( $all ) && isset( $all[ $this->target_key() ] ) && is_array( $all[ $this->target_key() ] ) ? $all[ $this->target_key() ] : array();
		$stale = isset( $state['stale'] ) && is_array( $state['stale'] ) ? array_map( 'strval', $state['stale'] ) : array();
		return array(
			'head'        => (string) ( $state['head'] ?? '' ),
			'upload_ref'  => (string) ( $state['upload_ref'] ?? '' ),
			'upload_head' => (string) ( $state['upload_head'] ?? '' ),
			'upload_next' => (string) ( $state['upload_next'] ?? '' ),
			'stale'       => $stale,
		);
	}

	/**
	 * Saves the state of this repository and branch.
	 *
	 * @param array<string,mixed> $state State (see STATE_OPTION).
	 * @return void
	 */
	private function save_state( array $state ): void {
		$all                        = get_option( self::STATE_OPTION, array() );
		$all                        = is_array( $all ) ? $all : array();
		$all[ $this->target_key() ] = $state;
		update_option( self::STATE_OPTION, $all, false );
	}

	/**
	 * Identifies this repository and branch in the target records.
	 *
	 * @return string 32 hex characters.
	 */
	private function target_key(): string {
		return substr( sha1( rtrim( (string) TargetConfig::value( $this, 'repository_url' ), '/' ) . '|' . TargetConfig::value( $this, 'branch' ) ), 0, 32 );
	}

	/**
	 * Client for the current settings.
	 *
	 * @return RemoteClient
	 */
	private function client(): RemoteClient {
		return new RemoteClient(
			$this->transport ?? new RequestsTransport( 1, 120.0 ),
			(string) TargetConfig::value( $this, 'repository_url' ),
			(string) TargetConfig::value( $this, 'username' ),
			(string) TargetConfig::value( $this, 'token' )
		);
	}

	/**
	 * True once the deadline has passed.
	 *
	 * @return bool
	 */
	private function out_of_time(): bool {
		return $this->deadline > 0 && microtime( true ) >= $this->deadline;
	}
}
