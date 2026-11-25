import {
	readFile,
	writeFile,
	stat,
	mkdir,
	copyFile,
	readdir,
} from 'node:fs/promises';
import { watch } from 'node:fs';
import path from 'path';
import { createRequire, findPackageJSON } from 'node:module';
import { createHash } from 'node:crypto';
import esbuild from 'esbuild';
import { sassPlugin } from 'esbuild-sass-plugin';
import { NodePackageImporter } from 'sass-embedded';
import { transform } from 'lightningcss';

async function exists( pathToCheck ) {
	try {
		await stat( pathToCheck );
		return true;
	} catch {
		return false;
	}
}

// Packages that should be bundled instead of externalized
const bundledPackages = [
	'@wordpress/icons',
	'@wordpress/fields',
	'@wordpress/interface',
	'@wordpress/global-styles-engine',
	'@zero-admin/design-system',
	'@wordpress/ui',
];

const packageSpecificBundles = new Map(
	Object.entries( {
		'@zero-admin/dataviews': new Set( [
			'@wordpress/dataviews',
			'@wordpress/dataviews/wp',
		] ),
	} )
);

const wpModuleCache = new Map();

// Check if a package has valid wpScriptModuleExports field.
// Valid formats are:
// - A string: "wpScriptModuleExports": "./build-module/index.mjs"
// - An object with "." key: "wpScriptModuleExports": { ".": "./build-module/index.mjs" }
function hasWpScriptModuleExports( packageJson ) {
	const exports = packageJson?.wpScriptModuleExports;
	if ( ! exports ) {
		return false;
	}
	// Check if it's a non-empty string
	if ( typeof exports === 'string' && exports.length > 0 ) {
		return true;
	}
	// Check if it's an object with a "." key (main export)
	if ( typeof exports === 'object' && exports[ '.' ] ) {
		return true;
	}
	return false;
}

// Find the module's `package.json` and check whether it has the `wpModule` flag,
// or wpScriptModuleExports field (indicating it's a WP script module).
async function isWpModule( importPath, resolvePath ) {
	if ( wpModuleCache.has( importPath ) ) {
		return wpModuleCache.get( importPath );
	}

	const { promise, resolve } = Promise.withResolvers();

	const packageJsonPath = findPackageJSON( importPath, resolvePath + '/' );
	if ( packageJsonPath ) {
		try {
			const packageJson = JSON.parse(
				await readFile( packageJsonPath, 'utf8' )
			);
			// Check for wpModule flag (used by local packages like @zero-admin/boot)
			// or wpScriptModuleExports (used by @wordpress/* packages that are script modules)
			resolve(
				packageJson?.wpModule ?? hasWpScriptModuleExports( packageJson )
			);
		} catch {
			resolve( false );
		}
	} else {
		resolve( false );
	}

	// Cache the promise (not the result) to handle race conditions
	wpModuleCache.set( importPath, promise );

	// Return the promise
	return promise;
}

// WordPress externals and asset plugin combined (includes WP modules)
const wordpressExternalsAndAssetPlugin = ( options = {} ) => ( {
	name: 'wordpress-externals-and-asset',
	async setup( build ) {
		const packageName = options.name;
		const dependencies = new Set();
		const moduleDependencies = new Map();
		const packageBundles =
			packageSpecificBundles.get( packageName ) ?? null;

		function addModuleDependency( name, kind ) {
			// If the same dependency is imported both as `static` and `dynamic`,
			// the resulting dependency has the `static` kind. The following logic
			// ensures that.
			if ( kind === 'static' ) {
				moduleDependencies.set( name, 'static' );
			} else if ( ! moduleDependencies.has( name ) ) {
				moduleDependencies.set( name, 'dynamic' );
			}
		}

		// Stub Node built-ins (e.g. crypto, buffer) so browser-compatible
		// libraries that optionally use them can still be bundled.
		build.onResolve( { filter: /^(crypto|buffer)$/ }, ( args ) => ( {
			path: args.path,
			namespace: 'node-builtin-stub',
		} ) );

		build.onLoad(
			{ filter: /.*/, namespace: 'node-builtin-stub' },
			() => ( {
				contents: `module.exports = {};`,
				loader: 'js',
			} )
		);

		// Handle WP modules (packages marked with wpModule: true) - use dynamic detection
		build.onResolve( { filter: /.*/ }, async ( args ) => {
			// Skip non-package imports early.
			if ( args.path.startsWith( '.' ) || path.isAbsolute( args.path ) ) {
				return;
			}

			// TODO: This package is loaded via PHP (Composer) until the npm package is available
			// Handled in onResolve below
			if ( args.path === '@wordpress/abilities' ) {
				return;
			}

			// Check if this is a WP module.
			if ( ! ( await isWpModule( args.path, args.resolveDir ) ) ) {
				return;
			}

			// Track module dependency for asset file - check if it's a dynamic import
			addModuleDependency(
				args.path,
				args.kind === 'dynamic-import' ? 'dynamic' : 'static'
			);

			return {
				path: args.path,
				external: true, // Keep as external import
			};
		} );

		// Handle all @wordpress/* packages automatically
		build.onResolve( { filter: /^@wordpress\// }, async ( args ) => {
			// TODO: This package is loaded via PHP (Composer) until the npm package is available
			if ( args.path === '@wordpress/abilities' ) {
				dependencies.add( 'wp-abilities' );
				return {
					path: args.path,
					namespace: 'wordpress-external',
				};
			}

			// Allow certain packages to bundle specified @wordpress dependencies.
			if ( packageBundles?.has( args.path ) ) {
				return;
			}

			// Skip if this package should be bundled
			if ( bundledPackages.includes( args.path ) ) {
				return; // Let esbuild handle it normally
			}

			// Skip if this is a WP module (already handled above)
			// This includes @wordpress/interactivity which is detected as a WP module
			if ( await isWpModule( args.path, args.resolveDir ) ) {
				return; // Let the WP module handler above handle it
			}

			// Track dependency for asset file (classic scripts that populate window.wp.*)
			const wpHandle = args.path.replace( '@wordpress/', 'wp-' );
			dependencies.add( wpHandle );

			return {
				path: args.path,
				namespace: 'wordpress-external',
			};
		} );

		build.onLoad(
			{ filter: /.*/, namespace: 'wordpress-external' },
			( args ) => {
				// Convert @wordpress/package-name to wp.packageName
				const wpGlobal = args.path
					.replace( '@wordpress/', '' )
					.replace( /-([a-z])/g, ( _, letter ) =>
						letter.toUpperCase()
					); // kebab-case to camelCase

				return {
					contents: `module.exports = window.wp.${ wpGlobal };`,
					loader: 'js',
				};
			}
		);

		// Handle React packages
		build.onResolve( { filter: /^react$/ }, () => {
			dependencies.add( 'react' );
			return {
				path: 'react',
				namespace: 'react-external',
			};
		} );

		build.onResolve( { filter: /^react-dom$/ }, () => {
			dependencies.add( 'react-dom' );
			return {
				path: 'react-dom',
				namespace: 'react-external',
			};
		} );

		build.onResolve( { filter: /^react\/jsx-runtime$/ }, () => {
			dependencies.add( 'react' ); // jsx-runtime is part of react
			return {
				path: 'react/jsx-runtime',
				namespace: 'react-external',
			};
		} );

		build.onLoad(
			{ filter: /react$/, namespace: 'react-external' },
			() => ( {
				contents: `module.exports = window.React;`,
				loader: 'js',
			} )
		);

		build.onLoad(
			{ filter: /react-dom$/, namespace: 'react-external' },
			() => ( {
				contents: `module.exports = window.ReactDOM;`,
				loader: 'js',
			} )
		);

		build.onLoad(
			{ filter: /react\/jsx-runtime$/, namespace: 'react-external' },
			() => ( {
				contents: `module.exports = window.ReactJSXRuntime;`,
				loader: 'js',
			} )
		);

		// Generate asset file at the end
		build.onEnd( async ( result ) => {
			if ( result.errors.length > 0 ) {
				return;
			}

			const packageNameString = packageName
				? `'name' => '${ packageName }', `
				: '';

			// Sort dependencies alphabetically for deterministic output
			const dependenciesString = Array.from( dependencies )
				.sort()
				.map( ( dep ) => `'${ dep }'` )
				.join( ', ' );

			// Sort module dependencies alphabetically for deterministic output
			const moduleDependenciesString = Array.from( moduleDependencies )
				.sort( ( [ a ], [ b ] ) => a.localeCompare( b ) )
				.map( ( [ id, kind ] ) => {
					if ( kind === 'static' ) {
						return `'${ id }'`;
					}
					return `array( 'id' => '${ id }', 'import' => 'dynamic')`;
				} )
				.join( ', ' );

			// Write asset file
			const outputDir =
				build.initialOptions.outdir ||
				path.dirname( build.initialOptions.outfile || 'build' );

			const files = Object.keys( build.initialOptions.entryPoints || {} );

			if ( build.initialOptions.stdin ) {
				files.push(
					path.basename(
						build.initialOptions.outfile,
						path.extname( build.initialOptions.outfile )
					)
				);
			}

			await Promise.all(
				files.map( async ( name ) => {
					// Compute content hash from the generated JS file for deterministic versioning
					// This ensures same source code produces same version, enabling proper caching
					const jsFilePath = path.join( outputDir, `${ name }.js` );
					let contentHash;
					try {
						const jsContent = await readFile( jsFilePath );
						contentHash = createHash( 'sha1' )
							.update( name )
							.update( jsContent )
							.digest( 'hex' )
							.slice( 0, 20 );
					} catch {
						// Fallback if JS file doesn't exist (shouldn't happen in normal builds)
						contentHash = createHash( 'sha1' )
							.update(
								name +
									dependenciesString +
									moduleDependenciesString
							)
							.digest( 'hex' )
							.slice( 0, 20 );
					}

					const assetContent = `<?php return array(${ packageNameString }'dependencies' => array(${ dependenciesString }), 'moduleDependencies' => array(${ moduleDependenciesString }), 'version' => '${ contentHash }');`;

					await writeFile(
						path.join( outputDir, `${ name }.asset.php` ),
						assetContent
					);
				} )
			);
		} );
	},
} );

// Get WordPress browserslist targets converted to esbuild format
async function getBrowserslistTargets() {
	try {
		const { default: browserslistToEsbuild } = await import(
			'browserslist-to-esbuild'
		);
		return browserslistToEsbuild();
	} catch ( error ) {
		console.warn(
			'Failed to get browserslist targets, falling back to es2020:',
			error.message
		);
		return [ 'es2020' ];
	}
}

const hashString = ( value ) =>
	createHash( 'sha1' ).update( value ).digest( 'hex' ).slice( 0, 10 );

/**
 * Create two esbuild plugins that share a single sass-embedded AsyncCompiler.
 *
 * Replaces the two `sassPlugin({ embedded: true })` instances that each spawn
 * their own Dart VM. A single shared compiler multiplexes all compilations
 * over one Dart process via protocol-buffer message IDs.
 *
 * @param {Object}                                options
 * @param {import('sass-embedded').AsyncCompiler} options.compiler      - Pre-initialised shared compiler.
 * @param {string}                                options.absWorkingDir - Absolute working directory for the NodePackageImporter.
 */
export function createSassPlugins( { compiler, absWorkingDir } ) {
	const pkgImporter = new NodePackageImporter( absWorkingDir );

	// Resolve bare module specifiers (e.g. "react-easy-crop/file.css",
	// "@wordpress/media-utils/build-style/style.css") through Node's
	// module resolution, handling pnpm's symlinked node_modules layout
	// and packages with restrictive `exports` fields.
	const require = createRequire( path.join( absWorkingDir, 'noop.js' ) );
	const nodeModulesImporter = {
		findFileUrl( url ) {
			if (
				url.startsWith( '.' ) ||
				url.startsWith( '/' ) ||
				url.startsWith( 'pkg:' )
			) {
				return null;
			}
			try {
				return new URL( `file://${ require.resolve( url ) }` );
			} catch {
				// require.resolve may fail when `exports` doesn't expose the
				// subpath. Fall back to locating the package root via its
				// package.json and resolving the subpath on the filesystem.
				try {
					const parts = url.split( '/' );
					const packageName = url.startsWith( '@' )
						? parts.slice( 0, 2 ).join( '/' )
						: parts[ 0 ];
					const subpath = url.startsWith( '@' )
						? parts.slice( 2 ).join( '/' )
						: parts.slice( 1 ).join( '/' );

					if ( ! subpath ) {
						return null;
					}

					const pkgJsonPath = findPackageJSON(
						packageName,
						absWorkingDir + '/'
					);
					if ( pkgJsonPath ) {
						const fullPath = path.join(
							path.dirname( pkgJsonPath ),
							subpath
						);
						return new URL( `file://${ fullPath }` );
					}
				} catch {
					// Intentionally empty
				}
				return null;
			}
		},
	};

	function makeSassPlugin( { filter, cssModules = false } ) {
		const styleTransform = inlineStyle( { cssModules } );

		return {
			name: cssModules ? 'shared-sass-modules' : 'shared-sass',
			setup( build ) {
				build.onLoad( { filter }, async ( args ) => {
					const isSass = /\.s[ac]ss$/.test( args.path );

					let css;
					let watchFiles = [];

					if ( isSass ) {
						const result = await compiler.compileAsync( args.path, {
							importers: [ pkgImporter, nodeModulesImporter ],
						} );
						css = result.css;
						watchFiles = [
							args.path,
							...result.loadedUrls
								.filter( ( url ) => url.protocol === 'file:' )
								.map( ( url ) => url.pathname ),
						];
					} else {
						const source = await readFile( args.path, 'utf8' );
						css = source;
						watchFiles = [ args.path ];
					}

					const contents = styleTransform( css );

					return { contents, loader: 'js', watchFiles };
				} );
			},
		};
	}

	return [
		makeSassPlugin( { filter: /\.module\.s?css$/, cssModules: true } ),
		makeSassPlugin( { filter: /\.(s[ac]ss|css)$/ } ),
	];
}

function inlineStyle( { cssModules = false } = {} ) {
	return function styleType( cssText ) {
		// Workaround for a lightningcss bug where `view-transition` selectors with spaces
		// (like `::view-transition-old( root )`) are a syntax error.
		// Can be removed once https://github.com/parcel-bundler/lightningcss/pull/1087 is merged.
		cssText = cssText.replace(
			/::view-transition-(old|new|image-pair|group)\s*\(\s*([^)]+?)\s+\)/g,
			'::view-transition-$1($2)'
		);

		// Transform the code: CSS modules and minification.
		const transformed = transform( {
			code: Buffer.from( cssText ),
			cssModules: cssModules && { pattern: '[content-hash]-[local]' },
			minify: false,
		} );

		const code = transformed.code.toString();

		// Hash the transformed output so different CSS module class names produce
		// different dedup hashes. Prevents collisions when the same source CSS is
		// built by different tools (e.g., @wordpress/ui vs wp-build) that generate
		// different class name hashes.
		const hash = hashString( code );

		let cssModule = `if (!document.head.querySelector("style[data-wp-hash='${ hash }']")) {
	const style = document.createElement("style");
	style.setAttribute("data-wp-hash", "${ hash }");
	style.appendChild(document.createTextNode(${ JSON.stringify( code ) }));
	document.head.appendChild(style);
}
`;

		// The CSS modules transform produces an `exports` object with class name mappings.
		// Sort the exports alphabetically to ensure deterministic output across builds.
		if ( transformed.exports ) {
			const exports = Object.fromEntries(
				Object.entries( transformed.exports )
					.map( ( [ key, value ] ) => [ key, value.name ] )
					.sort( ( [ a ], [ b ] ) => a.localeCompare( b ) )
			);

			cssModule += `export default ${ JSON.stringify( exports ) };\n`;
		}

		return cssModule;
	};
}

// Unified build function for both main and routes
export async function buildScript( options ) {
	const {
		entryPath,
		outputPath,
		outputDir,
		minify = true,
		nodeEnv = 'production',
		stdin,
		outfile,
		packageName,
		meta = false,
		skipBanner = false,
		absWorkingDir,
		compiler,
	} = options;

	const target = await getBrowserslistTargets();
	const plugins = [];

	const entryPoints =
		typeof entryPath === 'string' ? { index: entryPath } : entryPath;

	// Determine output directory
	const outdir = path.join(
		process.cwd(),
		outputDir || path.dirname( outputPath )
	);

	// Use shared compiler when available, otherwise fall back to esbuild-sass-plugin
	const sassPlugins = compiler
		? createSassPlugins( { compiler, absWorkingDir } )
		: [
				sassPlugin( {
					filter: /\.module\.s?css$/,
					type: inlineStyle( { cssModules: true, minify } ),
					embedded: true,
					importers: [ new NodePackageImporter( absWorkingDir ) ],
				} ),
				sassPlugin( {
					type: inlineStyle( { minify } ),
					embedded: true,
					importers: [ new NodePackageImporter( absWorkingDir ) ],
				} ),
		  ];

	const config = {
		...( stdin
			? { stdin, outfile: path.join( outdir, outfile ) }
			: { entryPoints, outdir } ),
		bundle: true,
		format: 'esm',
		target,
		metafile: meta,
		minify,
		sourcemap: false,
		jsx: 'automatic',
		tsconfig: path.join( process.cwd(), 'tsconfig.json' ),
		...( skipBanner ? {} : { banner: { js: "import '__i18n__';" } } ),
		absWorkingDir,
		resolveExtensions: [
			'.tsx',
			'.ts',
			'.jsx',
			'.js',
			'.scss',
			'.sass',
			'.css',
		],
		plugins: [
			...sassPlugins,
			wordpressExternalsAndAssetPlugin( { name: packageName } ),
			...plugins,
		],
		define: {
			'process.env.NODE_ENV': `"${ nodeEnv }"`,
		},
	};

	return esbuild.build( config ).then( ( result ) => {
		if ( meta ) {
			// Try to get output file
			const outputs = Object.entries( result.metafile.outputs );
			const mainOutput = outputs.find(
				( [ , output ] ) => output.entryPoint
			);
			const outputFile = mainOutput?.[ 0 ];

			// Write metadata file alongside the main output
			if ( outputFile ) {
				const metaPath = path.resolve(
					absWorkingDir,
					`${ outputFile }.meta.json`
				);
				const metaContent = JSON.stringify( result.metafile );
				writeFile( metaPath, metaContent );
			}
		}
		return result;
	} );
}

// Unified dev function
export async function devScript( options ) {
	const {
		entryPath,
		outputPath,
		outputDir,
		stdin,
		outfile,
		packageName,
		skipBanner = false,
		absWorkingDir,
		compiler,
	} = options;

	const target = await getBrowserslistTargets();
	const plugins = [];

	const entryPoints =
		typeof entryPath === 'string' ? { index: entryPath } : entryPath;

	// Determine output directory
	const outdir = path.join(
		process.cwd(),
		outputDir || path.dirname( outputPath )
	);

	// A plugin for logging successful rebuilds after failures
	const logRebuildsPlugin = {
		name: 'log-rebuild-success',
		setup( build ) {
			let hadErrors = false;

			// ANSI escape sequences for color and graphic rendition
			const RESET = '\x1b[0m';
			const BOLD = '\x1b[1m';
			const FG_GREEN = '\x1b[32m';
			const BG_GREEN = '\x1b[42m';

			build.onEnd( ( result ) => {
				const hasErrors = result.errors.length !== 0;
				if ( ! hasErrors && hadErrors ) {
					console.log(
						`${ FG_GREEN }%s${ RESET } ${ BG_GREEN }%s${ RESET } ${ BOLD }%s${ RESET }`,
						'✔',
						'[SUCCESS]',
						`${ packageName || 'module' } rebuilt successfully`
					);
				}
				hadErrors = hasErrors;
			} );
		},
	};

	// Use shared compiler when available, otherwise fall back to esbuild-sass-plugin
	const sassPlugins = compiler
		? createSassPlugins( { compiler, absWorkingDir } )
		: [
				sassPlugin( {
					filter: /\.module\.s?css$/,
					type: inlineStyle( { cssModules: true } ),
					embedded: true,
					importers: [ new NodePackageImporter( absWorkingDir ) ],
				} ),
				sassPlugin( {
					type: inlineStyle(),
					embedded: true,
					importers: [ new NodePackageImporter( absWorkingDir ) ],
				} ),
		  ];

	const config = {
		...( stdin
			? { stdin, outfile: `${ outdir }/${ outfile }` }
			: { entryPoints, outdir } ),
		bundle: true,
		format: 'esm',
		target,
		minify: false,
		sourcemap: true,
		jsx: 'automatic',
		tsconfig: path.join( process.cwd(), 'tsconfig.json' ),
		...( skipBanner ? {} : { banner: { js: "import '__i18n__';" } } ),
		absWorkingDir,
		resolveExtensions: [
			'.tsx',
			'.ts',
			'.jsx',
			'.js',
			'.scss',
			'.sass',
			'.css',
		],
		plugins: [
			...sassPlugins,
			wordpressExternalsAndAssetPlugin( { name: packageName } ),
			logRebuildsPlugin,
			...plugins,
		],
		define: {
			'process.env.NODE_ENV': '"development"',
		},
	};

	return esbuild.context( config );
}

async function generateEntryContent( routeDir ) {
	const [ packageJsonFile, hasStageFile, hasInspectorFile ] =
		await Promise.all( [
			readFile( path.join( routeDir, 'package.json' ), 'utf8' ),
			exists( path.join( routeDir, 'stage.tsx' ) ),
			exists( path.join( routeDir, 'inspector.tsx' ) ),
		] );

	if ( ! hasStageFile && ! hasInspectorFile ) {
		return;
	}

	const packageJson = JSON.parse( packageJsonFile );

	return `
		${ hasStageFile ? 'import stageDefault from "./stage.tsx";' : '' }
		${ hasInspectorFile ? 'import inspectorDefault from "./inspector.tsx";' : '' }
		export const stage = ${ hasStageFile ? 'stageDefault' : 'undefined' };
		export const inspector = ${
			hasInspectorFile ? 'inspectorDefault' : 'undefined'
		};
		export const path = ${ JSON.stringify( packageJson?.route?.path || '' ) };
	`;
}

// Build single route
export async function buildRoute(
	routeDir,
	routeName,
	{ meta = false, compiler } = {}
) {
	const routeTsxPath = path.join( routeDir, 'route.tsx' );

	const [ hasRouteFile, content ] = await Promise.all( [
		exists( routeTsxPath ),
		generateEntryContent( routeDir ),
	] );

	const buildPromises = [];
	const outputDir = `build/routes/${ routeName }`;

	// Create combined content module if stage.tsx or inspector.tsx exists
	if ( content ) {
		buildPromises.push(
			buildScript( {
				stdin: {
					contents: content,
					sourcefile: 'content.tsx',
					resolveDir: path.resolve( routeDir ),
					loader: 'tsx',
				},
				outfile: 'content.js',
				outputDir,
				packageName: routeName,
				meta,
				compiler,
				absWorkingDir: path.resolve( routeDir ),
			} )
		);
	}

	// Build route.tsx as ESM if it exists
	if ( hasRouteFile ) {
		buildPromises.push(
			buildScript( {
				entryPath: { route: 'route.tsx' },
				outputDir,
				packageName: routeName,
				meta,
				compiler,
				absWorkingDir: path.resolve( routeDir ),
			} )
		);
	}

	if ( buildPromises.length === 0 ) {
		throw new Error(
			`No valid entry files found in ${ routeDir }. Expected stage.tsx, inspector.tsx, or route.tsx.`
		);
	}

	await Promise.all( buildPromises );

	// Copy package.json
	await mkdir( outputDir, { recursive: true } );
	await copyFile(
		path.join( routeDir, 'package.json' ),
		path.join( outputDir, 'package.json' )
	);
}

// Dev single route
export async function devRoute( routeDir, routeName, { compiler } = {} ) {
	const routeTsxPath = path.join( routeDir, 'route.tsx' );

	const [ hasRouteFile, content ] = await Promise.all( [
		exists( routeTsxPath ),
		generateEntryContent( routeDir ),
	] );

	const outputDir = `build/routes/${ routeName }`;
	const contexts = [];

	// Handle route.tsx separately in dev mode
	if ( hasRouteFile ) {
		const routeContext = await devScript( {
			entryPath: { route: 'route.tsx' },
			outputDir,
			packageName: routeName,
			compiler,
			absWorkingDir: path.resolve( routeDir ),
		} );

		await routeContext.rebuild();
		await routeContext.watch();
		contexts.push( routeContext );
	}

	// Handle combined content module in dev mode
	if ( content ) {
		const contentContext = await devScript( {
			stdin: {
				contents: content,
				sourcefile: 'content.tsx',
				resolveDir: path.resolve( routeDir ),
				loader: 'tsx',
			},
			outfile: 'content.js',
			outputDir,
			packageName: routeName,
			compiler,
			absWorkingDir: path.resolve( routeDir ),
		} );

		await contentContext.rebuild();
		await contentContext.watch();
		contexts.push( contentContext );
	}

	if ( contexts.length === 0 ) {
		throw new Error(
			`No valid entry files found in ${ routeDir }. Expected stage.tsx, inspector.tsx, or route.tsx.`
		);
	}

	// Copy package.json
	await mkdir( outputDir, { recursive: true } );
	await copyFile(
		path.join( routeDir, 'package.json' ),
		path.join( outputDir, 'package.json' )
	);

	return contexts;
}

// Build WP module
export async function buildWpModule(
	moduleDir,
	moduleName,
	packageName,
	{ meta = false, compiler } = {}
) {
	let entryPath;
	for await ( const basename of [ 'index.ts', 'index.tsx' ] ) {
		const candidate = path.join( moduleDir, 'src', basename );
		if ( await exists( candidate ) ) {
			entryPath = path.join( 'src', basename );
			break;
		}
	}

	if ( ! entryPath ) {
		throw new Error(
			`No entry file found in ${ moduleDir }. Expected src/index.ts or src/index.tsx.`
		);
	}

	// Check package.json for frontendOnly flag to skip i18n banner
	let skipBanner = false;
	const packageJsonPath = path.join( moduleDir, 'package.json' );
	if ( await exists( packageJsonPath ) ) {
		try {
			const packageJson = JSON.parse(
				await readFile( packageJsonPath, 'utf8' )
			);
			skipBanner = packageJson.frontendOnly === true;
		} catch {
			// Ignore errors reading package.json
		}
	}

	const outputDir = `build/packages/${ moduleName }`;

	await buildScript( {
		entryPath,
		outputPath: `${ outputDir }/index.js`,
		packageName,
		meta,
		compiler,
		skipBanner,
		absWorkingDir: path.resolve( moduleDir ),
	} );

	// Copy package.json
	await mkdir( outputDir, { recursive: true } );
	await copyFile(
		path.join( moduleDir, 'package.json' ),
		path.join( outputDir, 'package.json' )
	);
}

// Build a single dashboard widget
export async function buildDashboardWidget(
	widgetDir,
	widgetName,
	{ meta = false, compiler } = {}
) {
	const renderPath = path.join( widgetDir, 'render.tsx' );
	const widgetPath = path.join( widgetDir, 'widget.ts' );

	const [ hasRenderFile, hasWidgetFile ] = await Promise.all( [
		exists( renderPath ),
		exists( widgetPath ),
	] );

	if ( hasRenderFile && hasWidgetFile ) {
		const outputDir = `build/dashboard-widgets/${ widgetName }`;

		const buildScriptArgs = {
			outputDir,
			packageName: widgetName,
			meta,
			compiler,
			absWorkingDir: path.resolve( widgetDir ),
		};
		await Promise.all( [
			buildScript( {
				entryPath: { render: 'render.tsx' },
				...buildScriptArgs,
			} ),
			buildScript( {
				entryPath: { widget: 'widget.ts' },
				...buildScriptArgs,
			} ),
		] );
	}
}

// Dev WP module
export async function devWpModule(
	moduleDir,
	moduleName,
	packageName,
	{ compiler } = {}
) {
	let entryPath;
	for await ( const basename of [ 'index.ts', 'index.tsx' ] ) {
		const candidate = path.join( moduleDir, 'src', basename );
		if ( await exists( candidate ) ) {
			entryPath = path.join( 'src', basename );
			break;
		}
	}

	if ( ! entryPath ) {
		throw new Error(
			`No entry file found in ${ moduleDir }. Expected src/index.ts or src/index.tsx.`
		);
	}

	// Check package.json for frontendOnly flag to skip i18n banner
	let skipBanner = false;
	const packageJsonPath = path.join( moduleDir, 'package.json' );
	if ( await exists( packageJsonPath ) ) {
		try {
			const packageJson = JSON.parse(
				await readFile( packageJsonPath, 'utf8' )
			);
			skipBanner = packageJson.frontendOnly === true;
		} catch {
			// Ignore errors reading package.json
		}
	}

	const outputDir = `build/packages/${ moduleName }`;

	const context = await devScript( {
		entryPath,
		outputPath: `${ outputDir }/index.js`,
		packageName,
		skipBanner,
		compiler,
		absWorkingDir: path.resolve( moduleDir ),
	} );

	// Copy package.json
	await mkdir( outputDir, { recursive: true } );
	await copyFile(
		path.join( moduleDir, 'package.json' ),
		path.join( outputDir, 'package.json' )
	);

	return context;
}

// Dev a single dashboard widget
export async function devDashboardWidget(
	widgetDir,
	widgetName,
	{ compiler } = {}
) {
	const renderPath = path.join( widgetDir, 'render.tsx' );
	const widgetPath = path.join( widgetDir, 'widget.ts' );

	const [ hasRenderFile, hasWidgetFile ] = await Promise.all( [
		exists( renderPath ),
		exists( widgetPath ),
	] );

	if ( hasRenderFile && hasWidgetFile ) {
		const outputDir = `build/dashboard-widgets/${ widgetName }`;
		const contexts = [];

		// Create dev context for render.tsx
		const renderContext = await devScript( {
			entryPath: { render: 'render.tsx' },
			outputDir,
			packageName: widgetName,
			compiler,
			absWorkingDir: path.resolve( widgetDir ),
		} );

		// Create dev context for widget.ts
		const widgetContext = await devScript( {
			entryPath: { widget: 'widget.ts' },
			outputDir,
			packageName: widgetName,
			compiler,
			absWorkingDir: path.resolve( widgetDir ),
		} );

		contexts.push( renderContext, widgetContext );

		// Return combined context for this widget
		return {
			contexts,
			async rebuild() {
				await Promise.all( contexts.map( ( ctx ) => ctx.rebuild() ) );
			},
			async watch() {
				await Promise.all( contexts.map( ( ctx ) => ctx.watch() ) );
			},
			async dispose() {
				await Promise.all( contexts.map( ( ctx ) => ctx.dispose() ) );
			},
		};
	}

	// Return empty context if no valid files found
	return {
		contexts: [],
		async rebuild() {},
		async watch() {},
		async dispose() {},
	};
}

// Build field collection
export async function buildFieldCollection(
	collectionDir,
	collectionName,
	{ meta = false, compiler } = {}
) {
	const outputDir = `build/fields/${ collectionName }`;

	// Ensure output directory exists
	await mkdir( outputDir, { recursive: true } );

	// Read collection config from source
	const collectionConfigPath = path.join( collectionDir, 'index.php' );
	if ( ! ( await exists( collectionConfigPath ) ) ) {
		throw new Error( `No index.php found in ${ collectionDir }` );
	}

	// Find all field directories with field.ts files
	// Sort directory entries alphabetically to ensure deterministic output across builds.
	const fieldDirs = ( await readdir( collectionDir ) ).sort();
	const fieldExtensions = [];
	const fields = {};

	for ( const fieldDirName of fieldDirs ) {
		// Skip non-directories and special files
		if ( fieldDirName === 'index.php' || fieldDirName === 'package.json' ) {
			continue;
		}

		const fieldDir = path.join( collectionDir, fieldDirName );
		const fieldPhpPath = path.join( fieldDir, 'field.php' );
		const fieldTsxPath = path.join( fieldDir, 'field.tsx' );
		const fieldTsPath = path.join( fieldDir, 'field.ts' );

		try {
			const fieldStat = await stat( fieldDir );
			const fieldPhpExists = await exists( fieldPhpPath );
			const fieldTsxExists = await exists( fieldTsxPath );
			const fieldTsExists = await exists( fieldTsPath );

			if ( fieldStat.isDirectory() && fieldPhpExists ) {
				// Copy field.php to build directory
				const buildFieldDir = path.join( outputDir, fieldDirName );
				await mkdir( buildFieldDir, { recursive: true } );
				await copyFile(
					fieldPhpPath,
					path.join( buildFieldDir, 'field.php' )
				);

				fields[ fieldDirName ] = true; // Mark that field exists

				// Check for either field.tsx or field.ts
				if ( fieldTsxExists || fieldTsExists ) {
					const relativePath = fieldTsxExists
						? path.relative( collectionDir, fieldTsxPath )
						: path.relative( collectionDir, fieldTsPath );
					fieldExtensions.push( {
						fieldId: fieldDirName,
						fieldTsPath: relativePath.startsWith( '.' )
							? relativePath
							: './' + relativePath,
					} );
				}
			}
		} catch {
			// Skip invalid field directories
			continue;
		}
	}

	// Copy index.php to build directory (similar to how routes copy package.json)
	await copyFile( collectionConfigPath, path.join( outputDir, 'index.php' ) );

	// If no field extensions found, we're done (but we still copied the config files)
	if ( fieldExtensions.length === 0 ) {
		return;
	}

	// Generate the field collection index.ts content
	const imports = fieldExtensions
		.map(
			( field, index ) =>
				`import { fieldExtensions as field${ index } } from '${ field.fieldTsPath }';`
		)
		.join( '\n' );

	const exports = fieldExtensions
		.map(
			( field, index ) =>
				`  Object.assign( { id: '${ field.fieldId }' }, field${ index } )`
		)
		.join( ',\n' );

	const indexContent = `// Auto-generated field extensions module
${ imports }

export default [
${ exports }
];`;

	// Build the field collection index module
	await buildScript( {
		stdin: {
			contents: indexContent,
			sourcefile: 'index.ts',
			resolveDir: '.',
			loader: 'ts',
		},
		outfile: 'index.js',
		outputDir,
		packageName: collectionName,
		meta,
		compiler,
		absWorkingDir: path.resolve( collectionDir ),
	} );
}

// Dev field collection
export async function devFieldCollection(
	collectionDir,
	collectionName,
	{ compiler } = {}
) {
	const outputDir = `build/fields/${ collectionName }`;
	const contexts = [];
	const phpWatchers = [];

	// Ensure output directory exists
	await mkdir( outputDir, { recursive: true } );

	// Read collection config from source
	const collectionConfigPath = path.join( collectionDir, 'index.php' );
	if ( ! ( await exists( collectionConfigPath ) ) ) {
		throw new Error( `No index.php found in ${ collectionDir }` );
	}

	// Find all field directories with field.ts files
	// Sort directory entries alphabetically to ensure deterministic output across builds.
	const fieldDirs = ( await readdir( collectionDir ) ).sort();
	const fieldExtensions = [];
	const fields = {};

	for ( const fieldDirName of fieldDirs ) {
		// Skip non-directories and special files
		if ( fieldDirName === 'index.php' || fieldDirName === 'package.json' ) {
			continue;
		}

		const fieldDir = path.join( collectionDir, fieldDirName );
		const fieldPhpPath = path.join( fieldDir, 'field.php' );
		const fieldTsxPath = path.join( fieldDir, 'field.tsx' );
		const fieldTsPath = path.join( fieldDir, 'field.ts' );

		try {
			const fieldStat = await stat( fieldDir );
			const fieldPhpExists = await exists( fieldPhpPath );
			const fieldTsxExists = await exists( fieldTsxPath );
			const fieldTsExists = await exists( fieldTsPath );

			if ( fieldStat.isDirectory() && fieldPhpExists ) {
				// Copy field.php to build directory
				const buildFieldDir = path.join( outputDir, fieldDirName );
				await mkdir( buildFieldDir, { recursive: true } );
				await copyFile(
					fieldPhpPath,
					path.join( buildFieldDir, 'field.php' )
				);

				fields[ fieldDirName ] = true; // Mark that field exists

				// Set up file watcher for PHP file
				const phpWatcher = watch( fieldPhpPath, async ( eventType ) => {
					if ( eventType === 'change' ) {
						try {
							await copyFile(
								fieldPhpPath,
								path.join( buildFieldDir, 'field.php' )
							);
							console.log(
								`📝 Updated ${ fieldDirName }/field.php`
							);
						} catch ( error ) {
							console.error(
								`❌ Error copying ${ fieldDirName }/field.php:`,
								error.message
							);
						}
					}
				} );
				phpWatchers.push( phpWatcher );

				// Check for either field.tsx or field.ts
				if ( fieldTsxExists || fieldTsExists ) {
					const relativePath = fieldTsxExists
						? path.relative( collectionDir, fieldTsxPath )
						: path.relative( collectionDir, fieldTsPath );
					fieldExtensions.push( {
						fieldId: fieldDirName,
						fieldTsPath: relativePath.startsWith( '.' )
							? relativePath
							: './' + relativePath,
					} );
				}
			}
		} catch {
			// Skip invalid field directories
			continue;
		}
	}

	// Copy index.php to build directory
	await copyFile( collectionConfigPath, path.join( outputDir, 'index.php' ) );

	// Set up file watcher for collection index.php
	const indexWatcher = watch( collectionConfigPath, async ( eventType ) => {
		if ( eventType === 'change' ) {
			try {
				await copyFile(
					collectionConfigPath,
					path.join( outputDir, 'index.php' )
				);
				console.log( `📝 Updated ${ collectionName }/index.php` );
			} catch ( error ) {
				console.error(
					`❌ Error copying ${ collectionName }/index.php:`,
					error.message
				);
			}
		}
	} );
	phpWatchers.push( indexWatcher );

	// If no field extensions found, return context with only PHP watchers
	if ( fieldExtensions.length === 0 ) {
		return {
			contexts: [],
			async rebuild() {},
			async watch() {},
			async dispose() {
				phpWatchers.forEach( ( watcher ) => watcher.close() );
			},
		};
	}

	// Generate the field collection index.ts content
	const imports = fieldExtensions
		.map(
			( field, index ) =>
				`import { fieldExtensions as field${ index } } from '${ field.fieldTsPath }';`
		)
		.join( '\n' );

	const exports = fieldExtensions
		.map(
			( field, index ) =>
				`  Object.assign( { id: '${ field.fieldId }' }, field${ index } )`
		)
		.join( ',\n' );

	const indexContent = `// Auto-generated field extensions module
${ imports }

export default [
${ exports }
];`;

	// Create dev context for the field collection index module
	const context = await devScript( {
		stdin: {
			contents: indexContent,
			sourcefile: 'index.ts',
			resolveDir: '.',
			loader: 'ts',
		},
		outfile: 'index.js',
		outputDir,
		packageName: collectionName,
		compiler,
		absWorkingDir: path.resolve( collectionDir ),
	} );

	contexts.push( context );

	// Return combined context for this field collection
	return {
		contexts,
		async rebuild() {
			await Promise.all( contexts.map( ( ctx ) => ctx.rebuild() ) );
		},
		async watch() {
			await Promise.all( contexts.map( ( ctx ) => ctx.watch() ) );
		},
		async dispose() {
			await Promise.all( contexts.map( ( ctx ) => ctx.dispose() ) );
			phpWatchers.forEach( ( watcher ) => watcher.close() );
		},
	};
}
