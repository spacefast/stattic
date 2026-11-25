interface RecentPostsRenderProps {
	attributes: {
		numberOfPosts?: number;
		showExcerpts?: boolean;
	};
}

export default function RecentPostsRender( {
	attributes,
}: RecentPostsRenderProps ) {
	const numberOfPosts = attributes.numberOfPosts || 3;
	const showExcerpts = attributes.showExcerpts;
	const allPosts = [
		{
			title: 'Getting Started with React',
			date: '2 days ago',
			excerpt:
				'Learn the basics of React development and create your first component.',
		},
		{
			title: 'Advanced TypeScript Tips',
			date: '5 days ago',
			excerpt:
				'Discover advanced TypeScript features to improve your code quality.',
		},
		{
			title: 'WordPress Development Best Practices',
			date: '1 week ago',
			excerpt:
				'Follow these best practices for building WordPress themes and plugins.',
		},
		{
			title: 'CSS Grid Layout Guide',
			date: '2 weeks ago',
			excerpt: 'Master CSS Grid to create complex layouts with ease.',
		},
		{
			title: 'JavaScript ES2024 Features',
			date: '3 weeks ago',
			excerpt:
				'Explore the latest JavaScript features and how to use them.',
		},
	];
	const displayedPosts = allPosts.slice( 0, numberOfPosts );
	return (
		<ul style={ { listStyle: 'none', padding: 0, margin: 0 } }>
			{ displayedPosts.map( ( post, index ) => (
				<li key={ index } style={ { marginBottom: '8px' } }>
					<strong>{ post.title }</strong>
					<br />
					<small>Published { post.date }</small>
					{ showExcerpts && (
						<>
							<br />
							<div
								style={ {
									fontSize: '0.9em',
									color: 'var( --wpds-color-fg-content-neutral-weak )',
									marginTop: '4px',
								} }
							>
								{ post.excerpt }
							</div>
						</>
					) }
				</li>
			) ) }
		</ul>
	);
}
