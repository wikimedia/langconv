<?php

namespace Wikimedia\LangConv;

use Wikimedia\Assert\Assert;

/**
 * Load and execute a finite-state transducer (FST) based converter or
 * bracketing machine from a compact JSON description.
 */
class FST {
	private const MAGIC_BYTES = 8; // 8 byte header w/ magic bytes

	// These pseudo-characters appear in the "output" side of the FST only.
	private const BYTE_IDENTITY = 0xFF;
	private const BYTE_RBRACKET = 0xFE;
	private const BYTE_LBRACKET = 0xFD;
	private const BYTE_FAIL     = 0xFC;
	// These pseudo-characters appear in the "input" side of the FST.
	private const BYTE_EOF      = 0xF8; // The highest possible input char
	private const BYTE_EPSILON  = 0x00; // Always appears first in sorted order

	/**
	 * Name of pFST file (for debugging and error messages).
	 * @var string
	 */
	private $name;

	/**
	 * FST, packed as a string.
	 * @var string
	 */
	private $pfst;

	/**
	 * @var bool
	 */
	private $justBrackets;

	/**
	 * @param string $name
	 * @param string $pfst
	 * @param bool $justBrackets
	 */
	private function __construct( string $name, string $pfst, bool $justBrackets ) {
		$this->name = $name;
		$this->pfst = $pfst;
		$this->justBrackets = $justBrackets;
		Assert::precondition(
			strlen( $pfst ) >= self::MAGIC_BYTES + 2 /*states, min*/,
			"pFST file too short: $name"
		);
		Assert::precondition(
			"pFST\0WM\0" ===
			substr( $pfst, 0, self::MAGIC_BYTES ),
			"Invalid pFST file: $name"
		);
	}

	/**
	 * @param string $input
	 * @param int|null $start
	 * @param int|null $end
	 * @return array
	 */
	public function split( string $input, ?int $start = null, ?int $end = null ): array {
		// Debugging helper: instead of an array of positions, split the
		// input at the bracket locations and return an array of strings.
		Assert::precondition( $this->justBrackets,
							 "Needs a bracket machine: " . $this->name );
		$end = $end ?? strlen( $input );
		$r = $this->run( $input, $start, $end );
		$r[] = $end;
		$i = 0;
		$nr = [];
		foreach ( $r as $j ) {
			$nr[] = substr( $input, $i, $j );
			$i = $j;
		}
		return $nr;
	}

	// Read zig-zag encoded variable length integers
	// (See [[:en:Variable-length_quantity#Zigzag_encoding]])
	private function readUnsignedV( int &$state ): int {
		$b = ord( $this->pfst[$state++] );
		$val = $b & 127;
		while ( $b & 128 ) {
			$val += 1;
			$b = ord( $this->pfst[$state++] );
			$val = ( $val << 7 ) + ( $b & 127 );
		}
		return $val;
	}

	private function readSignedV( int &$state ): int {
		$v = $this->readUnsignedV( $state );
		if ( $v & 1 ) { // sign bit is in LSB
			return -( $v >> 1 ) - 1;
		} else {
			return $v >> 1;
		}
	}

	/**
	 * @param string $input
	 * @param int|null $start
	 * @param int|null $end
	 * @param bool $unicode
	 * @return string|array
	 */
	public function run( string $input, ?int $start = null, ?int $end = null, bool $unicode = false ) {
		$start = $start ?? 0;
		$end = $end ?? strlen( $input );
		$countCodePoints = $this->justBrackets && $unicode;
		$initialState = self::MAGIC_BYTES + 2; /* eof state */
		$state = $initialState;
		$idx = $start;
		$outpos = 0;
		$stack = [ new BacktrackState( 0, 0, 0 ) ];
		$result = $stack[0];
		$result->partialBrackets[] = 0;

		// Add a character to the output.
		$emit = $this->justBrackets ?
			  function ( $code ) use ( $countCodePoints, &$result, &$outpos ) {
				  if ( $code === self::BYTE_LBRACKET || $code === self::BYTE_RBRACKET ) {
					  $result->partialBrackets[] = $outpos;
				  } elseif ( $countCodePoints && $code >= 0x80 && $code < 0xC0 ) {
					  /* Ignore UTF-8 continuation characters */
				  } else {
					  $outpos++;
				  }
			  } :
			  function ( $code ) use ( &$result, &$outpos ) {
				  $result->partialResult .= chr( $code );
				  $outpos++;
			  };

		// Save the current machine state before taking a non-deterministic edge;
		// if the machine fails, restart at the given `state`
		$save = function ( $epsEdge ) use ( &$idx, &$result, &$outpos, &$stack ) {
			$result = new BacktrackState( $epsEdge, $outpos, $idx );
			$stack[] = $result;
		};

		$reset = function ()
			use ( &$state, &$idx, &$outpos, &$result, &$stack, $emit ) {
				Assert::invariant( count( $stack ) > 1, $this->name ); # catch underflow
				$s = array_pop( $stack );
				$outpos = $s->outpos;
				$result = $stack[count( $stack ) - 1];
				$idx = $s->idx;
				// Get outByte from this edge, then jump to next state
				$state = $s->epsEdge + 1; /* skip over inByte */
				$edgeOut = ord( $this->pfst[$state++] );
				if ( $edgeOut !== self::BYTE_EPSILON ) {
					$emit( $edgeOut );
				}
				$edgeDest = $state;
				$edgeDest += $this->readSignedV( $state );
				$state = $edgeDest;
		};

		// This runs the machine until we reach the EOF state
		while ( $state >= $initialState ) {
			if ( $state === $initialState && count( $stack ) > 1 ) {
				// Memory efficiency: since the machine is universal we know
				// we'll never fail as long as we're in the initial state.
				$result = $stack[0];
				foreach ( array_splice( $stack, 1 ) as $s ) {
					if ( $this->justBrackets ) {
						foreach ( $s->partialBrackets as $b ) {
							$result->partialBrackets[] = $b;
						}
					} else {
						$result->partialResult .= $s->partialResult;
					}
				}
			}
			$edgeWidth = $this->readUnsignedV( $state );
			$nEdges = $this->readUnsignedV( $state );
			if ( $nEdges === 0 ) {
				$reset();
				continue;
			}
			// Read first edge to see if there are any epsilon edges
			$edge0 = $state;
			while ( ord( $this->pfst[$edge0] ) === self::BYTE_EPSILON ) {
				// If this is an epsilon edge, then save a backtrack state
				$save( $edge0 );
				$edge0 += $edgeWidth;
				$nEdges--;
				if ( $nEdges === 0 ) {
					$reset();
					continue 2;
				}
			}
			// Binary search for an edge matching c
			$c = $idx < $end ? ord( $input[$idx++] ) : /* pseudo-character: */ self::BYTE_EOF;
			$minIndex = 0;
			$maxIndex = $nEdges;
			while ( $minIndex !== $maxIndex ) {
				$currentIndex = ( $minIndex + $maxIndex ) >> 1;
				$targetEdge = $edge0 + ( $edgeWidth * $currentIndex );
				$inByte = ord( $this->pfst[$targetEdge] );
				if ( $inByte <= $c ) {
					$minIndex = $currentIndex + 1;
				} else {
					$maxIndex = $currentIndex;
				}
			}
			// (minIndex-1).inByte <= c, and maxIndex.inByte > c
			$targetEdge = $edge0 + ( $edgeWidth * ( $minIndex - 1 ) );
			$outByte = $minIndex > 0 ? ord( $this->pfst[$targetEdge + 1] ) : self::BYTE_FAIL;
			if ( $outByte === self::BYTE_FAIL ) {
				$reset();
				continue;
			}
			if ( $outByte !== self::BYTE_EPSILON ) {
				if ( $outByte === self::BYTE_IDENTITY ) {
					$outByte = $c; // Copy input byte to output
				}
				$emit( $outByte );
			}
			$state = $targetEdge + 2; // skip over inByte/outByte
			$state = $this->readSignedV( $state ) + ( $targetEdge + 2 );
		}

		// Ok, process the final state and return something.
		$result = $stack[0];
		foreach ( array_splice( $stack, 1 ) as $s ) {
			if ( $this->justBrackets ) {
				foreach ( $s->partialBrackets as $b ) {
					$result->partialBrackets[] = $b;
				}
			} else {
				$result->partialResult .= $s->partialResult;
			}
		}
		if ( $this->justBrackets ) {
			$result->partialBrackets[] = $outpos;
			return $result->partialBrackets;
		}
		Assert::invariant( strlen( $result->partialResult ) === $outpos, $this->name );
		return $result->partialResult;
	}

	/**
	 * Load an FST description and return a function which runs the machine.
	 * @param string $pfst The FST description as a filename (to be loaded synchronously)
	 * @param bool $justBrackets The machine will return an array of bracket locations,
	 *  instead of the converted text.
	 * @return FST
	 */
	public static function compile( string $pfst, $justBrackets = false ): FST {
		return new FST( $pfst, file_get_contents( $pfst ), $justBrackets );
	}
}
