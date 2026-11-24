<?php
/**
 * In-memory stand-in for a Git server's receive-pack service, for deployer tests that don't need git.test.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Support;

use Glaciate\Deploy\Aws\Transport;
use Glaciate\Deploy\Git\RemoteClient;

/**
 * Keeps refs only (packs are accepted unread) and applies ref updates the way receive-pack does: each command's old
 * ID must equal the ref's current value (all zeros for "doesn't exist"), or that ref is refused. With `atomic`, one
 * refusal refuses them all.
 */
final class FakeGit implements Transport {

	/** @var array<string,string> Ref name => commit ID. */
	public $refs = array();

	/** @var array<int,array{old:string,new:string,ref:string,result:string}> Every command received, in order. */
	public $commands = array();

	/** @var callable|null fn( string $ref ): ?string returns a reason to refuse deleting that ref, or null. */
	public $refuse_delete;

	/** @var callable|null fn( array $request ): bool; true applies the push but loses the answer. */
	public $lose_answer;

	/** @var callable|null Runs after each request. */
	public $after;

	public function send( array $requests ): array {
		$out = array();
		foreach ( $requests as $key => $request ) {
			$out[ $key ] = 'GET' === $request['method'] ? $this->advertise() : $this->receive( $request );
			if ( $this->after ) {
				call_user_func( $this->after, $request );
			}
		}
		return $out;
	}

	/**
	 * Commands received for the ref named $needle (a full `refs/...` name), or for refs whose name contains it.
	 *
	 * @return array<int,array{old:string,new:string,ref:string,result:string}>
	 */
	public function commands_for( string $needle ): array {
		return array_values(
			array_filter(
				$this->commands,
				static function ( $command ) use ( $needle ) {
					// A full ref name matches only itself: an old-format name is a prefix of the new ones.
					if ( 0 === strpos( $needle, 'refs/' ) ) {
						return $command['ref'] === $needle;
					}
					return false !== strpos( $command['ref'], $needle );
				}
			)
		);
	}

	private function advertise(): array {
		$caps  = 'report-status delete-refs atomic ofs-delta object-format=sha1';
		$body  = RemoteClient::pkt( "# service=git-receive-pack\n" ) . '0000';
		$first = true;
		foreach ( $this->refs as $ref => $id ) {
			$body .= RemoteClient::pkt( $id . ' ' . $ref . ( $first ? "\0" . $caps : '' ) . "\n" );
			$first = false;
		}
		if ( $first ) {
			$body .= RemoteClient::pkt( RemoteClient::ZERO . " capabilities^{}\0" . $caps . "\n" );
		}
		return $this->response( 'application/x-git-receive-pack-advertisement', $body . '0000' );
	}

	private function receive( array $request ): array {
		$commands = array();
		$atomic   = false;
		foreach ( RemoteClient::decode( $request['body'] ) as $line ) {
			if ( null === $line ) {
				break;
			}
			$nul = strpos( $line, "\0" );
			if ( false !== $nul ) {
				$atomic = in_array( 'atomic', explode( ' ', trim( substr( $line, $nul + 1 ) ) ), true );
				$line   = substr( $line, 0, $nul );
			}
			list( $old, $new, $ref ) = explode( ' ', trim( $line ) );
			$commands[]              = array(
				'old' => $old,
				'new' => $new,
				'ref' => $ref,
			);
		}
		$results = array();
		foreach ( $commands as $command ) {
			$current = $this->refs[ $command['ref'] ] ?? RemoteClient::ZERO;
			$reason  = $current === $command['old'] ? null : 'stale info';
			if ( null === $reason && RemoteClient::ZERO === $command['new'] && $this->refuse_delete ) {
				$reason = call_user_func( $this->refuse_delete, $command['ref'] );
			}
			$results[ $command['ref'] ] = null === $reason ? 'ok' : $reason;
		}
		if ( $atomic && array_diff( $results, array( 'ok' ) ) ) {
			foreach ( $results as $ref => $result ) {
				$results[ $ref ] = 'ok' === $result ? 'atomic transaction failed' : $result;
			}
		}
		$body = RemoteClient::pkt( "unpack ok\n" );
		foreach ( $commands as $command ) {
			$result = $results[ $command['ref'] ];
			if ( 'ok' === $result ) {
				if ( RemoteClient::ZERO === $command['new'] ) {
					unset( $this->refs[ $command['ref'] ] );
				} else {
					$this->refs[ $command['ref'] ] = $command['new'];
				}
			}
			$this->commands[] = $command + array( 'result' => $result );
			$body            .= RemoteClient::pkt( 'ok' === $result ? 'ok ' . $command['ref'] . "\n" : 'ng ' . $command['ref'] . ' ' . $result . "\n" );
		}
		if ( $this->lose_answer && call_user_func( $this->lose_answer, $request ) ) {
			return array(
				'status'  => 0,
				'headers' => array(),
				'body'    => '',
				'error'   => 'Connection reset',
			);
		}
		return $this->response( 'application/x-git-receive-pack-result', $body . '0000' );
	}

	private function response( string $type, string $body ): array {
		return array(
			'status'  => 200,
			'headers' => array( 'content-type' => $type ),
			'body'    => $body,
			'error'   => '',
		);
	}
}
