import { writeFile, readFile, mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join, basename } from 'node:path';

const __dirname = dirname( fileURLToPath( import.meta.url ) );

/**
 * Generate the PHP index file that combines modules.php, routes.php, dashboard widgets and field collections functionality.
 */
export async function generatePhpIndex() {
	const templatePath = join( __dirname, 'templates', 'index.php' );

	// Ensure build directory exists
	await mkdir( 'build', { recursive: true } );

	// Read the template
	const template = await readFile( templatePath, 'utf8' );

	// Generate a unique function prefix based on the current working directory
	const pluginName = basename( process.cwd() ).replace(
		/[^a-zA-Z0-9_]/g,
		'_'
	);
	const functionPrefix = `wp_plugin_build_${ pluginName }_`;

	// Replace function names in the template
	const functionMatches = Array.from(
		template.matchAll( /^\s*function (?<name>[^\(]+)/gm )
	);
	const uniquePhp = functionMatches.reduce( ( content, match ) => {
		const { name } = match.groups;
		return content.replace(
			new RegExp( name + '(?![a-zA-Z0-9_])', 'g' ),
			( m ) => functionPrefix + m.replace( /^wp_plugin_build_/, '' )
		);
	}, template );

	await writeFile( 'build/index.php', uniquePhp );
}
