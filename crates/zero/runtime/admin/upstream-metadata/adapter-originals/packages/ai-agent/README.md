# @zero-admin/ai-agent

An AI Agent chat interface for Zero Admin that provides an extensible
framework for integrating AI assistants using
[Agenttic]([upstream private reference omitted]).

## Installation

> [!NOTE]
> Currently, this package is intended for use within an NPM workspaces
> context. You do not need to install it from the public NPM registry.
> Instead, it is consumed as a wpModule in Zero Admin. Usage outside of
> this repository is not yet supported but should be possible in the future.

This package is automatically available as a wpModule when the AI agent
feature is enabled.

## Usage

### Basic Integration

The boot package loads this module when the AI agent feature is enabled:

```tsx
import { lazy, Suspense } from 'react';

const AIAgent = lazy( () => import( '@zero-admin/ai-agent' ) );

function Root() {
	const agentModules = useSelect(
		( select ) => select( zeroAdminStore ).getAgentModules(),
		[]
	);
	const showAIAgent = isAIAgentEnabled && agentModules.length > 0;

	return (
		<>
			{ showAIAgent && (
				<Suspense fallback={ null }>
					<AIAgent agentModules={ agentModules } />
				</Suspense>
			) }
		</>
	);
}
```

### Creating an Agent Provider

Agent providers export a configuration object that defines the agent endpoint
and setup:

```typescript
import { createJetpackAuthProvider } from '@zero-admin/agenttic-client';

export default {
	url: 'https://public-api.wordpress.com/wpcom/v2/ai/agent/woo-ai',
	setupClient: ( baseConfig ) => ( {
		...baseConfig,
		authProvider: createJetpackAuthProvider( errorHandler ),
		contextProvider: {
			getClientContext: () => ( { agent: 'woocommerce' } ),
		},
		toolProvider: {
			getAvailableTools: async () => [],
			executeTool: async () => ( {} ),
		},
	} ),
};
```

### Component Props

The `AIAgent` component accepts the following props:

```typescript
interface AIAgentProps {
	agentModules: string[]; // Array of provider module IDs to load
}
```

## Agenttic Integration

The package leverages the Agenttic package

-   `useAgentChat` hook from
    [`@zero-admin/agenttic-client`]([upstream private reference omitted])
    for chat state management and passing tools and context to the agent
-   `AgentUI` component from
    [`@zero-admin/agenttic-ui`]([upstream private reference omitted])
    for the chat interface
-   Full support for message history, processing states, and error handling

## Context Management

The AI agent uses a dedicated store to manage context entries that provide information about the current application state. This allows routes and components to share data with the AI agent.

### Using the Context Store

Import the store and use standard WordPress data API:

```typescript
import { store as agentContextStore } from '@zero-admin/ai-agent';
import { dispatch, select } from '@wordpress/data';
```

### Registering Context Entries

Routes and components can register context entries to provide information to the AI agent:

```typescript
import { store as agentContextStore } from '@zero-admin/ai-agent';
import type { EntityContextEntry } from '@zero-admin/ai-agent';
import type { ProductEntityRecord } from '@woocommerce-next/data';
import { dispatch, select } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

// Register an entity context entry (type parameter required for type safety)
const entry: EntityContextEntry< ProductEntityRecord > = {
	id: 'product-123',
	type: 'entity',
	entityType: 'product',
	entityId: '123',
	getData: () => {
		// Return current entity data using a closure
		// TypeScript will enforce the return type matches ProductEntityRecord
		return select( coreStore ).getEditedEntityRecord(
			'root',
			'product',
			'123'
		);
	},
};

// Register the entry with the AI agent context store
dispatch( agentContextStore ).addContextEntry( entry );

// Clean up on unmount
dispatch( agentContextStore ).removeContextEntry( 'product-123' );
```

**Note:** The `EntityContextEntry` interface is generic and requires a type parameter (`EntityContextEntry<T>`). Specify the entity type for full type safety.

### Retrieving Context Entries in Agent Providers

Agent provider implementations should retrieve context entries from the store:

```typescript
import { STORE_NAME } from '@zero-admin/ai-agent';
import { select } from '@wordpress/data';

// In your agent provider's createAgentConfig function:
export default {
	url: 'https://example.com/agent',
	createAgentConfig: async ( sessionId ) => {
		// Use the exported STORE_NAME constant to access the store
		const contextEntries = select( STORE_NAME ).getContextEntries();

		// Process context entries and include in agent configuration
		return {
			sessionId,
			contextProvider: {
				getClientContext: () => {
					// Call getData() on each entry to get fresh data
					return contextEntries.map( ( entry ) => ( {
						...entry,
						data: entry.getData?.(),
					} ) );
				},
			},
			// ... other config
		};
	},
};
```

### Context Entry Types

#### EntityContextEntry

Represents a WordPress entity (product, post, page, etc.):

```typescript
{
	id: string; // Unique identifier
	type: 'entity'; // Type discriminator
	entityType: string; // e.g., 'product', 'post'
	entityId: string; // Entity ID
	getData: () => T; // Closure to fetch current data
}
```

#### SitemapContextEntry

Provides navigation and entity type metadata for core WordPress functionality:

```typescript
{
	id: 'sitemap';
	type: 'sitemap';
	getData: () => SitemapData;
}
```

### Extensible Sitemap Architecture

The sitemap system is designed to be extensible. Core infrastructure (boot package) registers the base sitemap with WordPress post types and routes. App-specific extensions (like WooCommerce) register separate sitemap context entries with their own data.

**Core Sitemap** (registered by boot):

```typescript
dispatch( agentContextStore ).addContextEntry( {
	id: 'sitemap',
	type: 'sitemap',
	getData: () => ( {
		menuItems: [...],    // Navigation menu items
		postTypes: [...],    // WordPress post types
		expandedRoutes: [...] // Generic routes
	} )
} );
```

**App Extension** (e.g., WooCommerce):

```typescript
// Register app-specific sitemap data
dispatch( agentContextStore ).addContextEntry( {
	id: 'sitemap-woocommerce',
	type: 'sitemap',
	getData: () => ( {
		expandedRoutes: [...]  // Product-specific routes
	} )
} as SitemapExtensionContextEntry );
```

**Key extensibility mechanisms:**

1. **Multiple sitemap entries**: Apps register separate context entries that are merged by the AI agent
2. **Custom route patterns**: Apps define their own route patterns in their sitemap data

The AI agent consumes all sitemap-related context entries and merges them to build a complete understanding of the application's navigation structure. This allows apps to extend navigation metadata without modifying core infrastructure.

## API Reference

### `AIAgent`

Main component that renders the AI chat interface.

**Parameters:**

-   `agentModules` (string[]): Array of provider module IDs to load

**Returns:** JSX element or null if no provider is available
