#!/usr/bin/env node

import { cpus } from 'node:os';
import { opendir, readFile, writeFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import { Listr } from 'listr2';
import {
	buildRoute,
	buildWpModule,
	buildDashboardWidget,
	buildFieldCollection,
} from './config.js';
import {
	getAllRoutes,
	getAllWpModules,
	getAllDashboardWidgets,
	getAllFieldCollections,
} from './utils.js';
import { generatePhpIndex } from './php-generator.js';

async function collectMetafiles( rootDir ) {
	const metas = [];
	async function walk( dir ) {
		let dh;
		try {
			dh = await opendir( dir );
		} catch {
			return;
		}
		for await ( const entry of dh ) {
			const full = path.join( dir, entry.name );
			if ( entry.isDirectory() ) {
				await walk( full );
			} else if (
				entry.isFile() &&
				entry.name.endsWith( '.meta.json' )
			) {
				try {
					const json = JSON.parse( await readFile( full, 'utf8' ) );
					metas.push( json );
				} catch {
					// ignore invalid json
				}
			}
		}
	}
	await walk( rootDir );
	return metas;
}

function mergeMetafiles( metafiles ) {
	const merged = { inputs: {}, outputs: {} };
	// Carry forward a metafileVersion if present
	for ( const mf of metafiles ) {
		if ( mf && typeof mf.metafileVersion !== 'undefined' ) {
			merged.metafileVersion = mf.metafileVersion;
			break;
		}
	}
	for ( const mf of metafiles ) {
		if ( mf && mf.inputs ) {
			Object.assign( merged.inputs, mf.inputs );
		}
		if ( mf && mf.outputs ) {
			Object.assign( merged.outputs, mf.outputs );
		}
	}
	return merged;
}

export async function buildAll( { meta = false, metaOut = false } = {} ) {
	console.log( '🔨 Building WordPress plugin...\n' );

	// Share a single Dart Sass compiler across all build targets.
	const { initAsyncCompiler } = await import( 'sass-embedded' );
	const compiler = await initAsyncCompiler();

	try {
		const concurrent = cpus().length;
		const [ wpModules, dashboardWidgets, routes, fieldCollections ] =
			await Promise.all( [
				getAllWpModules(),
				getAllDashboardWidgets(),
				getAllRoutes(),
				getAllFieldCollections(),
			] );

		// Build WP modules
		if ( wpModules.length > 0 ) {
			console.log( `📦 Building ${ wpModules.length } WP module(s)...` );

			const wpModuleTasks = wpModules.map( ( wpModule ) => ( {
				title: wpModule.name,
				task: async () => {
					await buildWpModule(
						wpModule.dir,
						wpModule.name,
						wpModule.packageName,
						{ meta, compiler }
					);
				},
			} ) );

			await new Listr( wpModuleTasks, { concurrent } ).run();

			console.log();
		}

		// Build dashboard widgets
		if ( dashboardWidgets.length > 0 ) {
			console.log(
				`📦 Building ${ dashboardWidgets.length } dashboard widget(s)...`
			);

			const dashboardWidgetTasks = dashboardWidgets.map( ( widget ) => ( {
				title: widget.name,
				task: async () => {
					await buildDashboardWidget( widget.dir, widget.name, {
						meta,
						compiler,
					} );
				},
			} ) );

			await new Listr( dashboardWidgetTasks, { concurrent } ).run();

			console.log();
		}

		// Build routes
		if ( routes.length > 0 ) {
			console.log( `📦 Building ${ routes.length } route(s)...` );

			await new Listr(
				routes.map( ( route ) => ( {
					title: route.name,
					task: async () => {
						await buildRoute( route.dir, route.name, {
							meta,
							compiler,
						} );
					},
				} ) ),
				{ concurrent }
			).run();

			console.log();
		}

		// Build field collections
		if ( fieldCollections.length !== 0 ) {
			console.log(
				`🏷️  Building ${ fieldCollections.length } field collection(s)...`
			);

			// Build all field collections in parallel
			await new Listr(
				fieldCollections.map( ( collection ) => ( {
					title: collection.name,
					task: () =>
						buildFieldCollection( collection.dir, collection.name, {
							meta,
							compiler,
						} ),
				} ) ),
				{ concurrent }
			).run();

			console.log();
		}

		// Generate PHP index file
		console.log( '\n📝 Generating build/index.php...' );
		await generatePhpIndex();

		// If requested, aggregate all per-target meta.json into a single file
		if ( meta && metaOut ) {
			try {
				const metafiles = await collectMetafiles( 'build' );
				const merged = mergeMetafiles( metafiles );
				const outDir = path.dirname( metaOut );
				await mkdir( outDir, { recursive: true } );
				await writeFile( metaOut, JSON.stringify( merged, null, 2 ) );
				console.log( `\n📊 Wrote aggregated metafile: ${ metaOut }` );
			} catch ( aggErr ) {
				console.warn(
					'\n⚠️  Failed to aggregate metafiles:',
					aggErr.message
				);
			}
		}

		await compiler.dispose();

		console.log( '\n🎉 Build completed successfully!' );
	} catch ( error ) {
		await compiler.dispose();
		console.error( '\n❌ Build failed:', error.message );
		process.exit( 1 );
	}
}
