/**
 * Generates woo block references using block.json files.
 * Reads from  : assets/js
 * Publishes to: docs/block-references/block-references.md
 *
 * Adopted from: https://github.com/WordPress/gutenberg/pull/36183 Props to @mkaz
 */

/**
 * External dependencies
 */
const path = require( 'path' );
const fs = require( 'fs' );

/**
 * Path to root project directory.
 *
 * @type {string}
 */
const ROOT_DIR = path.resolve( __dirname, '../' );

/**
 * Path to blocks directory.
 *
 * @type {string}
 */
const BLOCK_LIBRARY_DIR = path.resolve( ROOT_DIR, 'assets/js' );

/**
 * Path to docs file.
 *
 * @type {string}
 */
const BLOCK_LIBRARY_DOCS_FILE = path.resolve(
	ROOT_DIR,
	'docs/block-references/block-references.md'
);

/**
 * Start token for matching string in doc file.
 *
 * @type {string}
 */
const START_TOKEN = '<!-- START Autogenerated - DO NOT EDIT -->';

/**
 * Start token for matching string in doc file.
 *
 * @type {string}
 */
const END_TOKEN = '<!-- END Autogenerated - DO NOT EDIT -->';

/**
 * Regular expression using tokens for matching in doc file.
 * Note: `.` does not match new lines, so [^] is used.
 *
 * @type {RegExp}
 */
const TOKEN_PATTERN = new RegExp( START_TOKEN + '[^]*' + END_TOKEN );

/**
 * Returns list of keys, filtering out any experimental
 * and wrapping keys with ~~ to strikeout false values.
 *
 * @type {Object} obj
 * @return {string[]} Array of truthy keys
 */
function getTruthyKeys( obj ) {
	return Object.keys( obj )
		.filter( ( key ) => ! key.startsWith( '__exp' ) )
		.map( ( key ) => ( obj[ key ] ? key : `~~${ key }~~` ) );
}

/**
 * Process list of object that may contain inner keys.
 * For example: spacing( margin, padding ).
 *
 * @param {Object} obj
 * @return {string[]} Array of keys (inner keys)
 */
function processObjWithInnerKeys( obj ) {
	const rtn = [];

	const kvs = getTruthyKeys( obj );

	kvs.forEach( ( key ) => {
		if ( Array.isArray( obj[ key ] ) ) {
			rtn.push( `${ key } (${ obj[ key ].sort().join( ', ' ) })` );
		} else if ( typeof obj[ key ] === 'object' ) {
			const innerKeys = getTruthyKeys( obj[ key ] );
			rtn.push( `${ key } (${ innerKeys.sort().join( ', ' ) })` );
		} else {
			rtn.push( key );
		}
	} );
	return rtn;
}

/**
 * Augment supports with additional default props.
 *
 * The color support if specified defaults background and text, if
 * not disabled. So adding { color: 'link' } support also brings along
 * background and text.
 *
 * @param {Object} supports - keys supported by blokc
 * @return {Object} supports augmented with defaults
 */
function augmentSupports( supports ) {
	if ( supports && 'color' in supports ) {
		// If backgroud or text is not specified (true or false)
		// then add it as true.a
		if (
			typeof supports.color === 'object' &&
			! ( 'background' in supports.color )
		) {
			supports.color.background = true;
		}
		if (
			typeof supports.color === 'object' &&
			! ( 'text' in supports.color )
		) {
			supports.color.text = true;
		}
	}
	return supports;
}

/**
 * Reads block.json file and returns markdown formatted entry.
 *
 * @param {string} filename
 *
 * @return {string} markdown
 */
function readBlockJSON( filename ) {
	const blockjson = require( filename );
	let supportsList = [];
	let attributes = [];

	if ( typeof blockjson.name === 'undefined' ) {
		return ``;
	}

	if ( typeof blockjson.supports !== 'undefined' ) {
		const supportsAugmented = augmentSupports( blockjson.supports );

		if ( supportsAugmented ) {
			supportsList = processObjWithInnerKeys( supportsAugmented );
		}
	}

	if ( typeof blockjson.attributes !== 'undefined' ) {
		attributes = getTruthyKeys( blockjson.attributes );
	}

	return `
## ${ blockjson.title } - ${ blockjson.name }

${ blockjson.description || '' }

-	**Name:** ${ blockjson.name }
-	**Category:** ${ blockjson.category || '' }
-   **Ancestor:** ${ blockjson.ancestor || '' }
-   **Parent:** ${ blockjson.parent || '' }
-	**Supports:** ${ supportsList || supportsList.sort().join( ', ' ) }
-	**Attributes:** ${ attributes || attributes.sort().join( ', ' ) }
`;
}

function getFiles( dir, filesArray, fileExtension ) {
	const files = fs.readdirSync( dir );

	files.forEach( ( file ) => {
		const filePath = path.join( dir, file );
		const fileStat = fs.statSync( filePath );

		if ( fileStat.isDirectory() ) {
			getFiles( filePath, filesArray, fileExtension );
		} else if ( path.extname( file ) === fileExtension ) {
			filesArray.push( filePath.replace( /\\/g, '/' ) );
		}
	} );

	return filesArray;
}

const files = getFiles( BLOCK_LIBRARY_DIR, [], '.json' );

let autogen = '';

files.forEach( ( file ) => {
	const markup = readBlockJSON( file );
	autogen += markup;
} );

let docsContent = fs.readFileSync( BLOCK_LIBRARY_DOCS_FILE, {
	encoding: 'utf8',
	flag: 'r',
} );

// Add delimiters back.
autogen = START_TOKEN + '\n' + autogen + '\n' + END_TOKEN;
docsContent = docsContent.replace( TOKEN_PATTERN, autogen );

// write back out
fs.writeFileSync( BLOCK_LIBRARY_DOCS_FILE, docsContent, { encoding: 'utf8' } );