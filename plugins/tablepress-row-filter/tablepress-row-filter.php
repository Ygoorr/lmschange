<?php
/*
Plugin Name: TablePress Extension: Row Filtering
Plugin URI: http://tablepress.org/extensions/row-filter/
Description: Extension for TablePress to filter table rows by using additional Shortcode parameters
Version: 1.1
Author: Tobias Bäthge
Author URI: http://tobias.baethge.com/
*/

// Prohibit direct script loading
defined( 'ABSPATH' ) || die( 'No direct script access allowed!' );

/**
 * Init TablePress_Row_Filter
 */
add_action( 'tablepress_run', array( 'TablePress_Row_Filter', 'init' ) );

/**
 * Class that contains the TablePress Row Filtering functionality
 * @author Tobias Bäthge
 * @since 1.0.0
 */
class TablePress_Row_Filter {

	/**
	 * Helper string that contains the name of the function that is used for the content matching
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected static $filter_compare_function = '';

	/**
	 * Register necessary plugin filter hooks
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_filter( 'tablepress_table_render_options', array( __CLASS__, 'filter_rows' ), 10, 2 );
		add_filter( 'tablepress_shortcode_table_default_shortcode_atts', array( __CLASS__, 'shortcode_attributes' ) );
	}

	/**
	 * Add the Extension's parameters as valid [[table /]] Shortcode attributes
	 *
	 * @since 1.0.0
	 *
	 * @param array $default_atts Default attributes for the TablePress [[table /]] Shortcode
	 * @return array Extended attributes for the Shortcode
	 */
	public static function shortcode_attributes( $default_atts ) {
		$default_atts['filter'] = '';
		$default_atts['filter_full_cell_match'] = false;
		$default_atts['filter_case_sensitive'] = false;
		$default_atts['filter_columns'] = 'all';
		return $default_atts;
	}

	/**
	 * Helper function for exact matching (strcmp() and strcasecmp() return 0 in case of exact match)
	 *
	 * @since 1.0.0
	 *
	 * @param string $a Cell content
	 * @param string $b Search term
	 * @return bool Whether string $a and $ are equal (thus the filter matches)
	 */
	public static function _filter_full_cell_match( $a, $b ) {
		return ( 0 === call_user_func( self::$filter_compare_function, $a, $b ) );
	}

	/**
	 * Helper function for part matching (strpos() and stripos() return false in case of no match)
	 *
	 * @since 1.0.0
	 *
	 * @param string $a Cell content
	 * @param string $b Search term
	 * @return bool Whether string $b can be found somewhere in $a (thus the filter matches)
	 */
	public static function _filter_cell_part_match( $a, $b ) {
		return ( false !== call_user_func( self::$filter_compare_function, $a, $b ) );
	}

	/**
	 * Add all rows to "hidden_rows" that do not fulfil the filter criterion
	 *
	 * @since 1.0.0
	 *
	 * @param array $render_options Output Options for the currently processed [[table /]] Shortcode
	 * @param array $table Table for the currently processed [[table /]] Shortcode
	 * @return array Possibly extended Output Options (in the "hide_rows" key)
	 */
	public static function filter_rows( $render_options, $table ) {
		// early exit, if no or an empty "filter" parameter is given
		if ( empty( $render_options['filter'] ) )
			return $render_options;

		$filter = $render_options['filter'];
		$filter_columns = $render_options['filter_columns'];

		$table['data'] = self::_remove_not_filtered_columns( $table['data'], $filter_columns );

		// bail if no columns are left to be filtered
		if ( 0 == count( $table['data'][0] ) )
			return $render_options;

		// determine which function should be used for matching, depending on parameters
		if ( $render_options['filter_full_cell_match'] ) {
			// the entire cell content has to match the search term
			$filter_match_function = '_filter_full_cell_match';
			if ( $render_options['filter_case_sensitive'] )
				self::$filter_compare_function = 'strcmp';
			else
				self::$filter_compare_function = 'strcasecmp';
		} else {
			// the search term can be anywhere in the cell content
			$filter_match_function = '_filter_cell_part_match';
			if ( $render_options['filter_case_sensitive'] )
				self::$filter_compare_function = 'strpos';
			else
				self::$filter_compare_function = 'stripos';
		}

		// && will be passed as &#038;&#038; or &amp;&amp;, depending on the used editor
		$filter = str_replace( array( '&#038;&#038;', '&amp;&amp;' ), '&&', $filter );

		// evaluate logic expressions in filter term
		if ( false !== strpos( $filter, '&&' ) ) {
			$compare = 'and';
			$filter_terms = explode( '&&', $filter );
		} elseif ( false !== strpos( $filter, '||' ) ) {
			$compare = 'or';
			$filter_terms = explode( '||', $filter );
		} else {
			$compare = 'none'; // single filter word
			$filter_terms = array( $filter );
		}

		$filter_terms = array_unique( $filter_terms );

		// remove HTML entities and turn them into characters, escape/slash other characters
		foreach ( $filter_terms as $key => $filter_term ) {
			$filter_terms[ $key ] = addslashes( wp_specialchars_decode( $filter_term, ENT_QUOTES, false, true ) );
		}

		$hidden_rows = array(); // rows that do not match the filter, will be hidden via "hide_rows" Shortcode attribute
		$row_match = false; // at least one row of the table matched the filter

		// preserve separately set value of "hide_rows" Shortcode attribute
		if ( '' != $render_options['hide_rows'] )
			$hidden_rows[] = $render_options['hide_rows'];

		// check for every row if it matches the filter
		$last_row_idx = count( $table['data'] ) - 1;
		foreach ( $table['data'] as $row_idx => $row ) {
			// always show the header/footer rows, if enabled
			if ( 0 == $row_idx && $render_options['table_head'] )
				continue;
			if ( $last_row_idx == $row_idx && $render_options['table_foot'] )
				continue;

			$found = array();
			foreach ( $filter_terms as $filter_term ) {
				$found[ $filter_term ] = false;
				foreach ( $row as $col_idx => $cell_content ) {
					if ( call_user_func( array( __CLASS__, $filter_match_function ), $cell_content, $filter_term ) ) { // parameter order is important (for str(i)pos())
						$found[ $filter_term ] = true;
						break;
					}
				}
			}

			// evaluate logic expressions
			switch ( $compare ) {
				case 'none':
				case 'or':
					if ( in_array( true, $found ) ) // at least one word was found / only filter word was found
						$row_match = true;
					else
						$hidden_rows[] = $row_idx + 1;
					break;
				case 'and':
					if ( ! in_array( false, $found ) ) // if not (at least one word was *not* found) == all words were found
						$row_match = true;
					else
						$hidden_rows[] = $row_idx + 1;
					break;
			}
		}

		// if search term(s) was/were not found in any of the rows, all rows need to be hidden
		// but only if first/last row is used as head/footer row, which were skipped above
		if ( ! $row_match && $render_options['table_head'] )
			$hidden_rows[] = 1;
		$last_row = count( $table['data'] );
		if ( ! $row_match && $render_options['table_foot'] )
			$hidden_rows[] = $last_row;

		// set "hide_rows" Shortcode attribute new, to hide all rows that did not match the filter
		$render_options['hide_rows'] = implode( ',', $hidden_rows );

		return $render_options;
	}

	/**
	 * Remove columns that shall not be filtered from the dataset
	 *
	 * @since 1.0.0
	 *
	 * @param array $table_data Full table data for the table to be filtered
	 * @param string $filter_columns List of columns that shall be searched by the filter
	 * @return array Reduced table data, that only contains the columns that shall be searched
	 */
	protected function _remove_not_filtered_columns( $table_data, $filter_columns ) {
		// add all columns to array if "all" value set for the filter_columns parameter
		if ( 'all' == $filter_columns )
			return $table_data;

		// we have a list of columns (possibly with ranges in it)
		$filter_columns = explode( ',', $filter_columns );
		// support for ranges like 3-6 or A-BA
		$range_cells = array();
		foreach ( $filter_columns as $key => $value ) {
			$range_dash = strpos( $value, '-' );
			if ( false !== $range_dash ) {
				unset( $filter_columns[ $key ] );
				$start = substr( $value, 0, $range_dash );
				if ( ! is_numeric( $start ) )
					$start = TablePress::letter_to_number( $start );
				$end = substr( $value, $range_dash + 1 );
				if ( ! is_numeric( $end ) )
					$end = TablePress::letter_to_number( $end );
				$current_range = range( $start, $end );
				$range_cells = array_merge( $range_cells, $current_range );
			}
		}
		$filter_columns = array_merge( $filter_columns, $range_cells );
		// parse single letters
		foreach ( $filter_columns as $key => $value ) {
			if ( ! is_numeric( $value ) )
				$filter_columns[ $key ] = TablePress::letter_to_number( $value );
			$filter_columns[ $key ] = (int)$filter_columns[ $key ];
		}
		// remove duplicate entries and sort the array
		$filter_columns = array_unique( $filter_columns );
		sort( $filter_columns, SORT_NUMERIC );
		// remove columns that shall not be filtered from the data
		$dont_filter_columns = array_diff( range( 1, count( $table_data[0] ) ), $filter_columns );
		foreach ( $table_data as $row_idx => $row ) {
			foreach ( $dont_filter_columns as $col_idx ) {
				unset( $row[ $col_idx - 1 ] ); // -1 due to zero based indexing
			}
			$table_data[$row_idx] = array_merge( $row );
		}

		return $table_data;
	}

} // class TablePress_Row_Filter