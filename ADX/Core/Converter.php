<?php

/**
 * AD-X
 *
 * Licensed under the BSD (3-Clause) license
 * For full copyright and license information, please see the LICENSE file
 *
 * @copyright		2012-2013 Robert Rossmann
 * @author			Robert Rossmann <rr.rossmann@me.com>
 * @link			https://github.com/Alaneor/AD-X
 * @license			http://choosealicense.com/licenses/bsd-3-clause		BSD (3-Clause) License
 */


namespace ADX\Core;

use ADX\Enums\Syntax;

/**
 * Provides conversion functionality between ldap and php datatypes
 *
 * All the conversion functionality is defined here. This class is used
 * internally to convert data automatically to php as it is received from
 * ldap server and also to convert data to ldap when data is being written
 * to ldap server.
 *
 * <p class="alert">The Converter <b>requires</b> the directory schema cache
 * to be present, otherwise no conversion will be made.</p>
 */
class Converter
{
	// No instances allowed
	final private function __construct() {}


	/**
	 * Convert a php-based value into ldap-compatible value
	 *
	 * @param		Attribute|string		The attribute's name the value belongs to
	 * @param		array|mixed				An array of values or a single value to be converted of any type
	 *
	 * @return		array|mixed				The converted data, ready for writing into server
	 */
	public static function to_ldap( $attribute, $values )
	{
		return static::_convert( 'to_l', $attribute, $values );
	}

	/**
	 * Convert an ldap-based value into php-compatible value
	 *
	 * @param		Attribute|string		The attribute's name the value belongs to
	 * @param		array|mixed				An array of values or a single value to be converted of any type
	 *
	 * @return		array|mixed				The converted data, ready for usage in php
	 */
	public static function from_ldap( $attribute, $values )
	{
		return static::_convert( 'to_p', $attribute, $values );
	}


	protected static function _convert( $direction, $attribute, $values )
	{
		// Load the schema information for the current attribute
		$schema		= Schema::get( "$attribute" );
		$ats		= $schema['attributesyntax'][0];
		$oms		= $schema['omsyntax'][0];

		$values		= (array)$values;
		$converted	= array();
		$method		= null;
		$class		= get_called_class();

		// Based on syntax, choose the corresponding syntax conversion method
		switch ( $ats )
		{
			case Syntax::Binary:
				$method = 'binary';
				break;

			case Syntax::Boolean:
				$method = 'bool';
				break;

			case Syntax::DnString:
				$method = 'object';
				break;

			case Syntax::Integer:
			case Syntax::LargeInt:
				// Conversion not needed
				break;

			case Syntax::UnicodeString:
			case Syntax::TeletexString:
			case Syntax::PrintableString:
			case Syntax::NumericString:
				// Conversion not needed
				break;

			case Syntax::Time:
				switch ( $oms )
				{
					case '23':	// UTC Time format
						$method = 'utctime';
						break;

					case '24':	// Generalised Time format
						$method = 'generalisedtime';
						break;
				}
				break;
		}

		// Define some syntax overrides based on attribute names ( a hit here will replace
		// the result from the previous switch )
		switch ( "$attribute" )
		{
			case 'currenttime':					// Not in schema, present on rootDSE
				$method = 'generalisedtime';
				break;

			case 'issynchronized':				// Not in schema, present on rootDSE
			case 'isglobalcatalogready':		// Not in schema, present on rootDSE
				$method = 'bool';
				break;

			case 'unicodepwd':					// A password always needs special treatment
				$method = $attribute;
				break;

			case 'objectguid':					// I want objectguid and msExchMailboxGuid shown
			case 'msexchmailboxguid':			// as AD tools show it ( like Powershell )
				$method = 'guid';
				break;

			// These attributes have LargeInt as syntax, but their meaning is different ( they represent a time )
			case 'pwdlastset':
			case 'accountexpires':
			case 'lastlogon':
			case 'lockouttime':
				$method = 'timestamp';
				break;
		}

		// If a conversion method has been found, call it for all values in $value
		$method = '_'.$direction.'_'.$method;

		if ( is_callable( [$class, $method] ) )
		{
			foreach ( $values as $value ) $converted[] = call_user_func( [$class, $method], $value );
		}
		else $converted = $values;	// No conversion has been found - use the raw data

		// Aaand we are done!
		return $converted;
	}

	// Conversion functions are defined below

	protected static function _to_l_timestamp( $timestamp )
	{
		$timestamp = (int)$timestamp;

		// 0 should always be, well, 0
		// -1 is a special treatment for pwdLastSet ( -1 is used to disable password change requirement )
		if ( $timestamp === 0 || $timestamp === -1 ) return $timestamp;

		return ( ( $timestamp * 10000000 ) + 11644473600 );
	}

	protected static function _to_p_timestamp( $timestamp )
	{
		$timestamp = (int)$timestamp;

		// The second part of the condition takes care of some crazy behaviour
		// ( documented, though ) of accountExpires, which can be zero
		// or 0x7FFFFFFFFFFFFFFF when the account is set to never expire.
		// Hopefully, this will not have any side effects...
		if ( $timestamp === 0 || $timestamp === hexdec( 0x7FFFFFFFFFFFFFFF ) ) return 0;

		return ( floor( $timestamp / 10000000 ) - 11644473600 );
	}

	protected static function _to_l_bool( $bool )
	{
		if ( $bool === true )	return 'TRUE';
		if ( $bool === false )	return 'FALSE';
	}

	protected static function _to_p_bool( $bool )
	{
		if ( strtolower( $bool ) === 'true' )	return true;
		if ( strtolower( $bool ) === 'false' )	return false;
	}

	protected static function _to_l_utctime( $time )
	{
		// Example: 20130327203157.0Z
		return date( "YmdHis", $time ).'.0Z';
	}

	protected static function _to_p_utctime( $time )
	{
		// Example imput: 20130327203157.0Z
		// Strip the timezone offset by exploding and pass the first part into DateTime for parsing
		// Timezone is hardcoded as for now, sorry...
		$time = new \DateTime( explode( '.', $time )[0], new \DateTimeZone( 'UTC' ) );

		return $time->getTimestamp();
	}

	protected static function _to_l_generalisedtime( $time )
	{
		// Example: 20130327203157.0Z
		return date( "YmdHis", $time ).'.0Z';
	}

	protected static function _to_p_generalisedtime( $time )
	{
		// Example imput: 20130327203157.0Z
		// Strip the timezone offset by exploding and pass the first part into DateTime for parsing
		// Timezone is hardcoded as for now, sorry...
		$time = new \DateTime( explode( '.', $time )[0], new \DateTimeZone( 'UTC' ) );

		return $time->getTimestamp();
	}

	protected static function _to_p_binary( $data )
	{
		return base64_encode( $data );
	}

	protected static function _to_l_object( $object )
	{
		if ( $object instanceof Object && ! $object->dn ) throw new InvalidOperationException( "The object $object is not stored on server - please save your object first, then retry the action" );

		return ( $object instanceof object ) ? $object->dn : (string)$object;
	}


	// Special cases

	/**
	 * Convert a string into quotation marks-encapsulated UTF-16LE string
	 *
	 * The password conversion is well described in the MSDN document available below.
	 *
	 * @see			<a href="http://msdn.microsoft.com/en-us/library/cc223248.aspx">MSDN - unicodePwd</a>
	 * @param		A password encoded in php's default charset ( as set in php.ini )
	 *
	 * @return		A quotation marks-encapsulated UTF-16LE string
	 */
	protected static function _to_l_unicodepwd( $password )
	{
		// Enclose the password in double quotes and convert it from
		// default php charset defined in php.ini to UTF-16LE encoding
		return iconv( ini_get( 'default_charset' ), 'utf-16le', "\"$password\"" );
	}

	protected static function _to_p_guid( $guid )
	{
		// An interesting piece of code I have found that converts
		// the objectguid exactly to what standard Powershell or other MS-based
		// tools show.
		$hex_guid = unpack( "H*hex", $guid );
		$hex	= $hex_guid["hex"];

		$hex1	= substr( $hex, -26, 2 ) . substr( $hex, -28, 2 ) . substr( $hex, -30, 2 ) . substr( $hex, -32, 2 );
		$hex2	= substr( $hex, -22, 2 ) . substr( $hex, -24, 2 );
		$hex3	= substr( $hex, -18, 2 ) . substr( $hex, -20, 2 );
		$hex4	= substr( $hex, -16, 4 );
		$hex5	= substr( $hex, -12, 12 );

		$guid = $hex1 . "-" . $hex2 . "-" . $hex3 . "-" . $hex4 . "-" . $hex5;

		return $guid;
	}

	protected static function _to_l_guid( $guid )
	{
		$guid = str_replace( '-', '', $guid );

		$octet_str  = substr( $guid, 6,		2 );
		$octet_str .= substr( $guid, 4,		2 );
		$octet_str .= substr( $guid, 2,		2 );
		$octet_str .= substr( $guid, 0,		2 );
		$octet_str .= substr( $guid, 10,	2 );
		$octet_str .= substr( $guid, 8,		2 );
		$octet_str .= substr( $guid, 14,	2 );
		$octet_str .= substr( $guid, 12,	2 );
		$octet_str .= substr( $guid, 16,	strlen( $guid ) );

		$hex_guid = '';

		for ( $i = 0; $i <= strlen( $octet_str ) - 2; $i = $i + 2 )
		{
			$hex_guid .=  "\\" . substr( $octet_str, $i, 2 );
		}

		return $hex_guid;
	}
}
