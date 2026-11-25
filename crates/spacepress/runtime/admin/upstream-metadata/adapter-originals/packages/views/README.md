# @first-admin/views

A lightweight package for managing DataViews view state with persistence using WordPress preferences.

The `@first-admin/views` package provides:

-   **Persistence**: Automatically saves and restores DataViews state using `@wordpress/preferences`
-   **View Modification Detection**: Tracks when views differ from their default state
-   **Reset Functionality**: Simple reset to default view capability
-   **Clean Integration**: Drop-in replacement for manual view state management

## Installation

> [!NOTE]
> Currently, this package is intended for use within an NPM workspaces context. You do not need to install it from the public NPM registry. Instead, import it directly as a workspace dependency in first-admin and it will be consumed as a module in WordPress. Usage outside of this repository is not yet supported but should be possible in the future.

To use this package in your workspace, add it to your `package.json` dependencies:

```json
{
	"dependencies": {
		"@first-admin/views": "workspace:*"
	}
}
```

## Usage

### Basic Hook Usage

Replace manual view state management with the `useView` hook:

```tsx
import { useView } from '@first-admin/views';

function MyDataViewsComponent() {
	const { view, isModified, updateView, resetToDefault } = useView( {
		kind: 'taxonomy',
		name: 'category',
		defaultView: {
			type: 'table',
			fields: [ 'name', 'slug', 'count' ],
			sort: { field: 'name', direction: 'asc' },
			// ... other default view properties
		},
	} );

	return (
		<>
			{ isModified && (
				<button onClick={ resetToDefault }>
					Reset to default view
				</button>
			) }
			<DataViews
				view={ view }
				onChangeView={ updateView }
				// ... other DataViews props
			/>
		</>
	);
}
```

### Adding URL-Based Navigation for pagination and search

For shareable pagination and search, add URL parameter support:

```tsx
import { useView } from '@first-admin/views';
import { useSearch, useNavigate } from '@wordpress/route';

function MyDataViewsComponent() {
	const searchParams = useSearch();
	const navigate = useNavigate();

	const { view, isModified, updateView, resetToDefault } = useView( {
		kind: 'taxonomy',
		name: 'category',
		defaultView: {
			type: 'table',
			fields: [ 'name', 'slug', 'count' ],
			sort: { field: 'name', direction: 'asc' },
		},
		// Enable URL-based navigation for page and search
		queryParams: searchParams,
		onChangeQueryParams: ( params ) => {
			navigate( {
				search: params,
			} );
		},
	} );

	// Note: isModified will be false for URL-based changes (page, search)
	// and true for preferences-based changes (layout, sorting, filters)

	return (
		<DataViews
			view={ view }
			onChangeView={ updateView }
			onReset={ isModified ? resetToDefault : false }
			// ... other DataViews props
		/>
	);
}
```

### Configuration

The `useView` hook accepts a configuration object with the following properties:

```typescript
interface ViewConfig {
	kind: string; // Entity kind (e.g., 'taxonomy', 'postType', 'root')
	name: string; // Specific entity name (e.g., 'category', 'post', 'user')
	defaultView: View; // Default view configuration from @first-admin/dataviews

	// Optional: URL-based navigation support
	queryParams?: {
		page?: number; // Current page number
		search?: string; // Search query
	};
	onChangeQueryParams?: ( params: {
		page?: number;
		search?: string;
	} ) => void; // Callback for URL updates
}
```

### Return Values

The hook returns an object with:

```typescript
interface UseViewReturn {
	view: View; // Current view state
	isModified: boolean; // True if view differs from default
	updateView: ( newView: View ) => void; // Update and persist view
	resetToDefault: () => void; // Reset to default view
}
```

## Features

### Hybrid Persistence Model

The package supports two different persistence strategies:

**URL-Based Navigation (Shareable):**

-   `page` and `search` parameters
-   Persisted in URL for shareability and bookmarking
-   Resets when navigating between pages
-   Does not trigger the `isModified` state

**Preferences-Based Configuration (Personal):**

-   Layout, sorting, filters, fields, perPage
-   Persisted using WordPress preferences per user
-   Maintains state across sessions and navigation
-   Triggers the `isModified` state when changed

### Automatic Persistence Keys

Preferences are saved using unique keys based on entity information. View settings persist globally across all tabs for the same entity:

-   **Pattern**: `dataviews-{kind}-{name}`
-   **Examples**:
    -   `dataviews-taxonomy-category` (shared across all category tabs)
    -   `dataviews-postType-post` (shared across all post status tabs)

### Modification Detection

The `isModified` flag indicates when preferences differ from defaults:

```tsx
// Show visual indicator when preferences are modified
{
	isModified && <Badge>Custom Configuration</Badge>;
}

// Reset control in the DataViews config popover:
// - false: disabled reset button
// - callback: enabled reset button
{
	<DataViews onReset={ isModified ? resetToDefault : false } />;
}
```

**Note**: URL-based changes (page, search) do not affect the `isModified` state since they represent navigation, not configuration.

## API Reference

### `useView( config )`

Main hook for view persistence with optional URL navigation support.

**Parameters:**

-   `config.kind` (string): Entity kind (e.g., 'postType', 'taxonomy', 'root')
-   `config.name` (string): Specific entity name (e.g., 'post', 'category', 'user')
-   `config.defaultView` (View): Default view configuration
-   `config.queryParams` (optional): Object with `page` and/or `search` from URL
-   `config.onChangeQueryParams` (optional): Callback for URL parameter updates

**Returns:** Object with `view`, `isModified`, `updateView`, and `resetToDefault`

### `loadView( config )`

Function for loading view state in route loaders with optional URL parameters.

**Parameters:**

-   `config.kind` (string): Entity kind (e.g., 'postType', 'taxonomy', 'root')
-   `config.name` (string): Specific entity name
-   `config.defaultView` (View): Default view configuration
-   `config.queryParams` (optional): Object with `page` and/or `search` from URL

**Returns:** `View` - The loaded view object with URL state

**Example with URL parameters:**

```typescript
// In route loader
const view = loadView( {
	kind: 'taxonomy',
	name: 'category',
	defaultView,
	queryParams: { page: search.page, search: search.search },
} );
```

### DataViews Component

The `DataViews` component is a thin wrapper around `@first-admin/dataviews` that supports
an `onReset` prop for view persistence reset controls and a `tabs` slot.

```tsx
import { DataViews, useView } from '@first-admin/views';

function MyPage( props ) {
	const { view, isModified, resetToDefault } = useView( {
		kind: props.kind,
		name: props.name,
		defaultView: props.defaultView,
	} );

	return (
		<DataViews< T >
			{ ...props }
			onReset={ isModified ? resetToDefault : false }
			tabs={ <Tabs ... /> }
		/>
	);
}
```
