<?php

namespace ClassicPress\Directory;

trait Helpers
{

	/**
	 * Get all substrings within text that are found between two other, specified strings
	 *
	 * Avoids parsing HTML with regex
	 *
	 * Returns an array
	 *
	 * See https://stackoverflow.com/a/27078384
	 */
	function get_markdown_contents( $str, $startDelimiter, $endDelimiter ) {
		$contents = [];
		$startDelimiterLength = strlen( $startDelimiter );
		$endDelimiterLength = strlen( $endDelimiter );
		$startFrom = $contentStart = $contentEnd = 0;

		while ( $contentStart = strpos( $str, $startDelimiter, $startFrom ) ) {
			$contentStart += $startDelimiterLength;
			$contentEnd = strpos( $str, $endDelimiter, $contentStart );
			if ( $contentEnd === false ) {
				break;
			}
			$contents[] = substr( $str, $contentStart, $contentEnd - $contentStart );
			$startFrom = $contentEnd + $endDelimiterLength;
		}

		return $contents;
	}

}
