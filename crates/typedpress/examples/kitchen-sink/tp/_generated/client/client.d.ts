export type Id<Kind extends string = string> = number & {
	readonly __tp_kind?: Kind;
};
export type Role = "guest" | "subscriber" | "author" | "editor" | "admin";
export interface User {
	id: number;
	name: string;
	role: Role;
	member?: Member | null;
}
export interface Media {
	id: number;
	url: string;
	mime: string;
	size: number;
	width: number | null;
	height: number | null;
	alt: string | null;
	filename: string;
	createdAt: string;
	sizes: Record<string, {
		url: string;
		width: number;
		height: number;
	}>;
	focal: {
		x: number;
		y: number;
	} | null;
	author: number | null;
	caption: string;
}
export interface PrivateFile {
	id: number;
	bucket: string;
	filename: string;
	mime: string;
	size: number;
	ownerId: number | null;
	createdAt: string;
	url: string;
}
export interface RevisionSummary {
	id: number;
	version: number;
	op: "create" | "update" | "delete" | "restore" | "purge";
	at: string;
	user: {
		id: number;
		name: string;
	} | null;
	schemaMigration: string | null;
	changed: string[] | null;
}
export interface RevisionDiff<T = Record<string, unknown>> {
	from: number;
	to: number;
	changes: {
		field: keyof T & string;
		from: unknown;
		to: unknown;
	}[];
}
export type Status = "draft" | "published" | "scheduled";
export type Locale = never;
export interface Event {
	id: Id<"events">;
	title: string;
	slug: string | null;
	description: string | null;
	startsOn: string;
	venue: string;
	poster: number | null;
	gallery: number[];
	handout: number | null;
	format: "class" | "demo";
	equipment: ("apron" | "knife" | "scale")[];
	registrationOpen: boolean;
	demoUrl: string | null;
	ingredients: {
		name: string;
		grams: number;
	}[] | null;
	host: {
		name: string;
		website: string | null;
	} | null;
	sections: ({
		layout: "tip";
		heading: string;
		body: string;
	} | {
		layout: "quote";
		text: string;
		author: string;
	})[] | null;
	status: "draft" | "published" | "scheduled";
	publishedAt: string | null;
	author: number | null;
	createdAt: string;
	updatedAt: string;
	deletedAt: string | null;
	deletedBy: number | null;
	uid: string | null;
}
export interface EventInsert {
	title: string;
	slug?: string | null;
	description?: string | null;
	startsOn: string;
	venue?: string;
	poster?: number | null;
	gallery?: number[];
	handout?: number | null;
	format?: "class" | "demo";
	equipment?: ("apron" | "knife" | "scale")[];
	registrationOpen?: boolean;
	demoUrl?: string | null;
	ingredients?: {
		name: string;
		grams: number;
	}[] | null;
	host?: {
		name: string;
		website?: string | null;
	} | null;
	sections?: ({
		layout: "tip";
		heading: string;
		body: string;
	} | {
		layout: "quote";
		text: string;
		author: string;
	})[] | null;
	status?: "draft" | "published" | "scheduled";
	publishedAt?: string | null;
	uid?: string | null;
}
export interface EventPatch {
	title?: string;
	slug?: string | null;
	description?: string | null;
	startsOn?: string;
	venue?: string;
	poster?: number | null;
	gallery?: number[];
	handout?: number | null;
	format?: "class" | "demo";
	equipment?: ("apron" | "knife" | "scale")[];
	registrationOpen?: boolean;
	demoUrl?: string | null;
	ingredients?: {
		name: string;
		grams: number;
	}[] | null;
	host?: {
		name: string;
		website?: string | null;
	} | null;
	sections?: ({
		layout: "tip";
		heading: string;
		body: string;
	} | {
		layout: "quote";
		text: string;
		author: string;
	})[] | null;
	status?: "draft" | "published" | "scheduled";
	publishedAt?: string | null;
	uid?: string | null;
}
export interface EventWith {
	registrations?: true | {
		where?: RegistrationFilter;
		orderBy?: RegistrationOrder;
		limit?: number;
		offset?: number;
		with?: RegistrationWith;
	};
	activity?: true | {
		where?: ActivityFilter;
		orderBy?: ActivityOrder;
		limit?: number;
		offset?: number;
		with?: ActivityWith;
	};
	poster?: true;
	gallery?: true;
	handout?: true;
}
export interface EventFilter {
	id?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	title?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	slug?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	description?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	startsOn?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	venue?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	poster?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	gallery?: [] | null | {
		has?: number;
		eq?: [];
		isNull?: true;
		isNotNull?: true;
	};
	handout?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	format?: "class" | "demo" | null | {
		eq?: "class" | "demo" | null;
		ne?: "class" | "demo" | null;
		gt?: "class" | "demo";
		gte?: "class" | "demo";
		lt?: "class" | "demo";
		lte?: "class" | "demo";
		in?: ("class" | "demo")[];
		notIn?: ("class" | "demo")[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	equipment?: [] | null | {
		has?: "apron" | "knife" | "scale";
		eq?: [];
		isNull?: true;
		isNotNull?: true;
	};
	registrationOpen?: boolean | null | {
		eq?: boolean | null;
		ne?: boolean | null;
		gt?: boolean;
		gte?: boolean;
		lt?: boolean;
		lte?: boolean;
		in?: boolean[];
		notIn?: boolean[];
		isNull?: true;
		isNotNull?: true;
	};
	demoUrl?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	ingredients?: {
		name: string;
		grams: number;
	}[] | null | {
		eq?: {
			name: string;
			grams: number;
		}[] | null;
		ne?: {
			name: string;
			grams: number;
		}[] | null;
		isNull?: true;
		isNotNull?: true;
	};
	host?: {
		name: string;
		website: string | null;
	} | null | {
		eq?: {
			name: string;
			website: string | null;
		} | null;
		ne?: {
			name: string;
			website: string | null;
		} | null;
		isNull?: true;
		isNotNull?: true;
	};
	sections?: ({
		layout: "tip";
		heading: string;
		body: string;
	} | {
		layout: "quote";
		text: string;
		author: string;
	})[] | null | {
		eq?: ({
			layout: "tip";
			heading: string;
			body: string;
		} | {
			layout: "quote";
			text: string;
			author: string;
		})[] | null;
		ne?: ({
			layout: "tip";
			heading: string;
			body: string;
		} | {
			layout: "quote";
			text: string;
			author: string;
		})[] | null;
		isNull?: true;
		isNotNull?: true;
	};
	status?: "draft" | "published" | "scheduled" | null | {
		eq?: "draft" | "published" | "scheduled" | null;
		ne?: "draft" | "published" | "scheduled" | null;
		gt?: "draft" | "published" | "scheduled";
		gte?: "draft" | "published" | "scheduled";
		lt?: "draft" | "published" | "scheduled";
		lte?: "draft" | "published" | "scheduled";
		in?: ("draft" | "published" | "scheduled")[];
		notIn?: ("draft" | "published" | "scheduled")[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	publishedAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	author?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	createdAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	updatedAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	deletedAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	deletedBy?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	uid?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	registrations?: true | RegistrationFilter;
	activity?: true | ActivityFilter;
	AND?: EventFilter[];
	OR?: EventFilter[];
	NOT?: EventFilter;
}
export type EventOrder = Partial<Record<"id" | "title" | "slug" | "description" | "startsOn" | "venue" | "poster" | "handout" | "format" | "registrationOpen" | "demoUrl" | "status" | "publishedAt" | "author" | "createdAt" | "updatedAt" | "deletedAt" | "deletedBy" | "uid", "asc" | "desc">> | string;
export interface Post {
	id: Id<"posts">;
	title: string;
	slug: string;
	content: string | null;
	excerpt: string | null;
	minutes: number | null;
	status: "draft" | "published" | "scheduled";
	publishedAt: string | null;
	author: number | null;
	createdAt: string;
	updatedAt: string;
	deletedAt: string | null;
	deletedBy: number | null;
	uid: string | null;
}
export interface PostInsert {
	title: string;
	slug?: string;
	content?: string | null;
	excerpt?: string | null;
	minutes?: number | null;
	status?: "draft" | "published" | "scheduled";
	publishedAt?: string | null;
	uid?: string | null;
}
export interface PostPatch {
	title?: string;
	slug?: string;
	content?: string | null;
	excerpt?: string | null;
	minutes?: number | null;
	status?: "draft" | "published" | "scheduled";
	publishedAt?: string | null;
	uid?: string | null;
}
export interface PostWith {}
export interface PostFilter {
	id?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	title?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	slug?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	content?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	excerpt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	minutes?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	status?: "draft" | "published" | "scheduled" | null | {
		eq?: "draft" | "published" | "scheduled" | null;
		ne?: "draft" | "published" | "scheduled" | null;
		gt?: "draft" | "published" | "scheduled";
		gte?: "draft" | "published" | "scheduled";
		lt?: "draft" | "published" | "scheduled";
		lte?: "draft" | "published" | "scheduled";
		in?: ("draft" | "published" | "scheduled")[];
		notIn?: ("draft" | "published" | "scheduled")[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	publishedAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	author?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	createdAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	updatedAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	deletedAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	deletedBy?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	uid?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	AND?: PostFilter[];
	OR?: PostFilter[];
	NOT?: PostFilter;
}
export type PostOrder = Partial<Record<"id" | "title" | "slug" | "content" | "excerpt" | "minutes" | "status" | "publishedAt" | "author" | "createdAt" | "updatedAt" | "deletedAt" | "deletedBy" | "uid", "asc" | "desc">> | string;
export interface Recipe {
	id: Id<"recipes">;
	title: string;
	slug: string;
	content: string | null;
	excerpt: string | null;
	servings: number;
	topicIds: Id<"topics">[];
	status: "draft" | "published" | "scheduled";
	publishedAt: string | null;
	author: number | null;
	createdAt: string;
	updatedAt: string;
	deletedAt: string | null;
	deletedBy: number | null;
	uid: string | null;
}
export interface RecipeInsert {
	title: string;
	slug?: string;
	content?: string | null;
	excerpt?: string | null;
	servings?: number;
	topicIds?: Id<"topics">[];
	status?: "draft" | "published" | "scheduled";
	publishedAt?: string | null;
	uid?: string | null;
}
export interface RecipePatch {
	title?: string;
	slug?: string;
	content?: string | null;
	excerpt?: string | null;
	servings?: number;
	topicIds?: number[];
	status?: "draft" | "published" | "scheduled";
	publishedAt?: string | null;
	uid?: string | null;
}
export interface RecipeWith {
	topicIds?: true | {
		where?: TopicFilter;
		orderBy?: TopicOrder;
		limit?: number;
		offset?: number;
		with?: TopicWith;
	};
}
export interface RecipeFilter {
	id?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	title?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	slug?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	content?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	excerpt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	servings?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	topicIds?: [] | {
		has?: number;
		eq?: [];
		NOT?: {
			has?: number;
		};
	} | true | false | TopicFilter;
	status?: "draft" | "published" | "scheduled" | null | {
		eq?: "draft" | "published" | "scheduled" | null;
		ne?: "draft" | "published" | "scheduled" | null;
		gt?: "draft" | "published" | "scheduled";
		gte?: "draft" | "published" | "scheduled";
		lt?: "draft" | "published" | "scheduled";
		lte?: "draft" | "published" | "scheduled";
		in?: ("draft" | "published" | "scheduled")[];
		notIn?: ("draft" | "published" | "scheduled")[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	publishedAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	author?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	createdAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	updatedAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	deletedAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	deletedBy?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	uid?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	AND?: RecipeFilter[];
	OR?: RecipeFilter[];
	NOT?: RecipeFilter;
}
export type RecipeOrder = Partial<Record<"id" | "title" | "slug" | "content" | "excerpt" | "servings" | "status" | "publishedAt" | "author" | "createdAt" | "updatedAt" | "deletedAt" | "deletedBy" | "uid", "asc" | "desc">> | string;
export interface Handbook {
	id: Id<"handbook">;
	title: string;
	slug: string;
	content: string | null;
	parent: Id<"handbook"> | null;
	order: number;
	status: "draft" | "published" | "scheduled";
	publishedAt: string | null;
	author: number | null;
	createdAt: string;
	updatedAt: string;
	deletedAt: string | null;
	deletedBy: number | null;
	uid: string | null;
}
export interface HandbookInsert {
	title: string;
	slug?: string;
	content?: string | null;
	parent?: Id<"handbook"> | null;
	order?: number;
	status?: "draft" | "published" | "scheduled";
	publishedAt?: string | null;
	uid?: string | null;
}
export interface HandbookPatch {
	title?: string;
	slug?: string;
	content?: string | null;
	parent?: number | null;
	order?: number;
	status?: "draft" | "published" | "scheduled";
	publishedAt?: string | null;
	uid?: string | null;
}
export interface HandbookWith {
	parent?: true | {
		where?: HandbookFilter;
		orderBy?: HandbookOrder;
		limit?: number;
		offset?: number;
		with?: HandbookWith;
	};
	children?: true | {
		where?: HandbookFilter;
		orderBy?: HandbookOrder;
		limit?: number;
		offset?: number;
		with?: HandbookWith;
	};
}
export interface HandbookFilter {
	id?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	title?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	slug?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	content?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	parent?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	} | HandbookFilter;
	order?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	status?: "draft" | "published" | "scheduled" | null | {
		eq?: "draft" | "published" | "scheduled" | null;
		ne?: "draft" | "published" | "scheduled" | null;
		gt?: "draft" | "published" | "scheduled";
		gte?: "draft" | "published" | "scheduled";
		lt?: "draft" | "published" | "scheduled";
		lte?: "draft" | "published" | "scheduled";
		in?: ("draft" | "published" | "scheduled")[];
		notIn?: ("draft" | "published" | "scheduled")[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	publishedAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	author?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	createdAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	updatedAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	deletedAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	deletedBy?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	uid?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	children?: true | HandbookFilter;
	AND?: HandbookFilter[];
	OR?: HandbookFilter[];
	NOT?: HandbookFilter;
}
export type HandbookOrder = Partial<Record<"id" | "title" | "slug" | "content" | "parent" | "order" | "status" | "publishedAt" | "author" | "createdAt" | "updatedAt" | "deletedAt" | "deletedBy" | "uid", "asc" | "desc">> | string;
export interface Registration {
	id: Id<"registrations">;
	eventId: Id<"events">;
	memberId: Id<"members">;
	seats: number;
	code: string;
	preferences?: {
		allergens: string[];
	} | null;
	bookedOn: string;
	updatedAt: string;
	author: number | null;
	createdAt: string;
	deletedAt: string | null;
	deletedBy: number | null;
}
export interface RegistrationInsert {
	eventId: Id<"events">;
	memberId: Id<"members">;
	seats?: number;
	code?: string;
	preferences?: {
		allergens: string[];
	} | null;
	bookedOn?: string;
	updatedAt?: string;
}
export interface RegistrationPatch {
	eventId?: number;
	memberId?: number;
	seats?: number;
	code?: string;
	preferences?: {
		allergens: string[];
	} | null;
	bookedOn?: string;
	updatedAt?: string;
}
export interface RegistrationWith {
	eventId?: true | {
		where?: EventFilter;
		orderBy?: EventOrder;
		limit?: number;
		offset?: number;
		with?: EventWith;
	};
	memberId?: true | {
		where?: MemberFilter;
		orderBy?: MemberOrder;
		limit?: number;
		offset?: number;
		with?: MemberWith;
	};
}
export interface RegistrationFilter {
	id?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	eventId?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	} | EventFilter;
	memberId?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	} | MemberFilter;
	seats?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	code?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	preferences?: {
		allergens: string[];
	} | null | {
		eq?: {
			allergens: string[];
		} | null;
		ne?: {
			allergens: string[];
		} | null;
		isNull?: true;
		isNotNull?: true;
	};
	bookedOn?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	updatedAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	author?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	createdAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	deletedAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	deletedBy?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	AND?: RegistrationFilter[];
	OR?: RegistrationFilter[];
	NOT?: RegistrationFilter;
}
export type RegistrationOrder = Partial<Record<"id" | "eventId" | "memberId" | "seats" | "code" | "bookedOn" | "updatedAt" | "author" | "createdAt" | "deletedAt" | "deletedBy", "asc" | "desc">> | string;
export interface Activity {
	id: Id<"activity">;
	eventId: Id<"events"> | null;
	summary: string;
	createdAt: string;
	author: number | null;
	updatedAt: string;
	deletedAt: string | null;
	deletedBy: number | null;
}
export interface ActivityInsert {
	eventId?: Id<"events"> | null;
	summary: string;
	createdAt?: string;
}
export interface ActivityPatch {
	eventId?: number | null;
	summary?: string;
	createdAt?: string;
}
export interface ActivityWith {
	eventId?: true | {
		where?: EventFilter;
		orderBy?: EventOrder;
		limit?: number;
		offset?: number;
		with?: EventWith;
	};
}
export interface ActivityFilter {
	id?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	eventId?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	} | EventFilter;
	summary?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	createdAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	author?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	updatedAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	deletedAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	deletedBy?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	AND?: ActivityFilter[];
	OR?: ActivityFilter[];
	NOT?: ActivityFilter;
}
export type ActivityOrder = Partial<Record<"id" | "eventId" | "summary" | "createdAt" | "author" | "updatedAt" | "deletedAt" | "deletedBy", "asc" | "desc">> | string;
export interface Member {
	id: Id<"members">;
	email: string;
	name: string;
	website: string | null;
	plan: "free" | "supporter";
	role: "guest" | "subscriber" | "author" | "editor" | "admin";
}
export interface MemberInsert {
	email: string;
	name: string;
	website?: string | null;
	plan?: "free" | "supporter";
	role?: "guest" | "subscriber" | "author" | "editor" | "admin";
	password?: string;
}
export interface MemberPatch {
	email?: string;
	name?: string;
	website?: string | null;
	plan?: "free" | "supporter";
	role?: "guest" | "subscriber" | "author" | "editor" | "admin";
	password?: string;
}
export interface MemberWith {
	registrations?: true | {
		where?: RegistrationFilter;
		orderBy?: RegistrationOrder;
		limit?: number;
		offset?: number;
		with?: RegistrationWith;
	};
}
export interface MemberFilter {
	id?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	email?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	name?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	website?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	plan?: "free" | "supporter" | null | {
		eq?: "free" | "supporter" | null;
		ne?: "free" | "supporter" | null;
		gt?: "free" | "supporter";
		gte?: "free" | "supporter";
		lt?: "free" | "supporter";
		lte?: "free" | "supporter";
		in?: ("free" | "supporter")[];
		notIn?: ("free" | "supporter")[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	role?: "guest" | "subscriber" | "author" | "editor" | "admin" | null | {
		eq?: "guest" | "subscriber" | "author" | "editor" | "admin" | null;
		ne?: "guest" | "subscriber" | "author" | "editor" | "admin" | null;
		gt?: "guest" | "subscriber" | "author" | "editor" | "admin";
		gte?: "guest" | "subscriber" | "author" | "editor" | "admin";
		lt?: "guest" | "subscriber" | "author" | "editor" | "admin";
		lte?: "guest" | "subscriber" | "author" | "editor" | "admin";
		in?: ("guest" | "subscriber" | "author" | "editor" | "admin")[];
		notIn?: ("guest" | "subscriber" | "author" | "editor" | "admin")[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	registrations?: true | RegistrationFilter;
	AND?: MemberFilter[];
	OR?: MemberFilter[];
	NOT?: MemberFilter;
}
export type MemberOrder = Partial<Record<"id" | "email" | "name" | "website" | "plan" | "role", "asc" | "desc">> | string;
export interface Topic {
	id: Id<"topics">;
	name: string;
	slug: string;
	description: string;
	count: number;
	url: string | null;
	color: string | null;
	uid: string | null;
}
export interface TopicInsert {
	name: string;
	slug?: string;
	description?: string;
	color?: string | null;
	uid?: string | null;
}
export interface TopicPatch {
	name?: string;
	slug?: string;
	description?: string;
	color?: string | null;
	uid?: string | null;
}
export interface TopicWith {
	recipes?: true | {
		where?: RecipeFilter;
		orderBy?: RecipeOrder;
		limit?: number;
		offset?: number;
		with?: RecipeWith;
	};
}
export interface TopicFilter {
	id?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	name?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	slug?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	description?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	count?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	url?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	color?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	uid?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	recipes?: true | RecipeFilter;
	AND?: TopicFilter[];
	OR?: TopicFilter[];
	NOT?: TopicFilter;
}
export type TopicOrder = Partial<Record<"id" | "name" | "slug" | "description" | "count" | "url" | "color" | "uid", "asc" | "desc">> | string;
export interface Supply {
	id: Id<"supplies">;
	uid: string | null;
	name: string;
	slug: string;
	description: string;
	sku: string | null;
	shortDescription: string | null;
	price: string | null;
	status: "draft" | "pending" | "scheduled" | "published" | "private";
	images: number[];
	virtual: boolean;
	downloadable: boolean;
	downloads: {
		id: string;
		name: string;
		file?: string;
	}[];
	type: string;
	purchasable: boolean;
	availability: string;
	url: string | null;
	categories: Id<"supplyCategories">[];
	tags: Id<"supplyTags">[];
}
export interface SupplyInsert {
	uid?: string | null;
	name: string;
	slug?: string;
	description?: string;
	sku?: string | null;
	shortDescription?: string | null;
	price?: string | null;
	status?: "draft" | "pending" | "scheduled" | "published" | "private";
	images?: number[];
	virtual?: boolean;
	downloadable?: boolean;
	downloads?: {
		id: string;
		name: string;
		file: string;
	}[];
	categories?: Id<"supplyCategories">[];
	tags?: Id<"supplyTags">[];
}
export interface SupplyPatch {
	uid?: string | null;
	name?: string;
	slug?: string;
	description?: string;
	sku?: string | null;
	shortDescription?: string | null;
	price?: string | null;
	status?: "draft" | "pending" | "scheduled" | "published" | "private";
	images?: number[];
	virtual?: boolean;
	downloadable?: boolean;
	downloads?: {
		id: string;
		name: string;
		file?: string;
	}[];
	categories?: number[];
	tags?: number[];
}
export interface SupplyWith {
	categories?: true | {
		where?: SupplyCategoryFilter;
		orderBy?: SupplyCategoryOrder;
		limit?: number;
		offset?: number;
		with?: SupplyCategoryWith;
	};
	tags?: true | {
		where?: SupplyTagFilter;
		orderBy?: SupplyTagOrder;
		limit?: number;
		offset?: number;
		with?: SupplyTagWith;
	};
	images?: true;
}
export interface SupplyFilter {
	id?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	uid?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	name?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	slug?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	description?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	sku?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	shortDescription?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	price?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	status?: "draft" | "pending" | "scheduled" | "published" | "private" | null | {
		eq?: "draft" | "pending" | "scheduled" | "published" | "private" | null;
		ne?: "draft" | "pending" | "scheduled" | "published" | "private" | null;
		gt?: "draft" | "pending" | "scheduled" | "published" | "private";
		gte?: "draft" | "pending" | "scheduled" | "published" | "private";
		lt?: "draft" | "pending" | "scheduled" | "published" | "private";
		lte?: "draft" | "pending" | "scheduled" | "published" | "private";
		in?: ("draft" | "pending" | "scheduled" | "published" | "private")[];
		notIn?: ("draft" | "pending" | "scheduled" | "published" | "private")[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	images?: [] | null | {
		has?: number;
		eq?: [];
		isNull?: true;
		isNotNull?: true;
	};
	virtual?: boolean | null | {
		eq?: boolean | null;
		ne?: boolean | null;
		gt?: boolean;
		gte?: boolean;
		lt?: boolean;
		lte?: boolean;
		in?: boolean[];
		notIn?: boolean[];
		isNull?: true;
		isNotNull?: true;
	};
	downloadable?: boolean | null | {
		eq?: boolean | null;
		ne?: boolean | null;
		gt?: boolean;
		gte?: boolean;
		lt?: boolean;
		lte?: boolean;
		in?: boolean[];
		notIn?: boolean[];
		isNull?: true;
		isNotNull?: true;
	};
	downloads?: {
		id: string;
		name: string;
		file?: string;
	}[] | null | {
		eq?: {
			id: string;
			name: string;
			file?: string;
		}[] | null;
		ne?: {
			id: string;
			name: string;
			file?: string;
		}[] | null;
		isNull?: true;
		isNotNull?: true;
	};
	type?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	purchasable?: boolean | null | {
		eq?: boolean | null;
		ne?: boolean | null;
		gt?: boolean;
		gte?: boolean;
		lt?: boolean;
		lte?: boolean;
		in?: boolean[];
		notIn?: boolean[];
		isNull?: true;
		isNotNull?: true;
	};
	availability?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	url?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	categories?: [] | {
		has?: number;
		eq?: [];
		NOT?: {
			has?: number;
		};
	} | true | false | SupplyCategoryFilter;
	tags?: [] | {
		has?: number;
		eq?: [];
		NOT?: {
			has?: number;
		};
	} | true | false | SupplyTagFilter;
	AND?: SupplyFilter[];
	OR?: SupplyFilter[];
	NOT?: SupplyFilter;
}
export type SupplyOrder = Partial<Record<"id" | "uid" | "name" | "slug" | "description" | "sku" | "shortDescription" | "price" | "status" | "virtual" | "downloadable" | "type" | "purchasable" | "availability" | "url", "asc" | "desc">> | string;
export interface SupplyCategory {
	id: Id<"supplyCategories">;
	uid: string | null;
	name: string;
	slug: string;
	description: string;
	parent: Id<"supplyCategories"> | null;
	count: number;
	url: string | null;
}
export interface SupplyCategoryInsert {
	uid?: string | null;
	name: string;
	slug?: string;
	description?: string;
	parent?: Id<"supplyCategories"> | null;
}
export interface SupplyCategoryPatch {
	uid?: string | null;
	name?: string;
	slug?: string;
	description?: string;
	parent?: number | null;
}
export interface SupplyCategoryWith {
	supplies?: true | {
		where?: SupplyFilter;
		orderBy?: SupplyOrder;
		limit?: number;
		offset?: number;
		with?: SupplyWith;
	};
}
export interface SupplyCategoryFilter {
	id?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	uid?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	name?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	slug?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	description?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	parent?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	count?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	url?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	supplies?: true | SupplyFilter;
	AND?: SupplyCategoryFilter[];
	OR?: SupplyCategoryFilter[];
	NOT?: SupplyCategoryFilter;
}
export type SupplyCategoryOrder = Partial<Record<"id" | "uid" | "name" | "slug" | "description" | "parent" | "count" | "url", "asc" | "desc">> | string;
export interface SupplyTag {
	id: Id<"supplyTags">;
	uid: string | null;
	name: string;
	slug: string;
	description: string;
	count: number;
	url: string | null;
}
export interface SupplyTagInsert {
	uid?: string | null;
	name: string;
	slug?: string;
	description?: string;
}
export interface SupplyTagPatch {
	uid?: string | null;
	name?: string;
	slug?: string;
	description?: string;
}
export interface SupplyTagWith {
	supplies?: true | {
		where?: SupplyFilter;
		orderBy?: SupplyOrder;
		limit?: number;
		offset?: number;
		with?: SupplyWith;
	};
}
export interface SupplyTagFilter {
	id?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	uid?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	name?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	slug?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	description?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	count?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	url?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	supplies?: true | SupplyFilter;
	AND?: SupplyTagFilter[];
	OR?: SupplyTagFilter[];
	NOT?: SupplyTagFilter;
}
export type SupplyTagOrder = Partial<Record<"id" | "uid" | "name" | "slug" | "description" | "count" | "url", "asc" | "desc">> | string;
export interface Order {
	id: Id<"orders">;
	status: string;
	currency: string;
	precision: number;
	createdAt: string;
	updatedAt: string;
	paidAt: string | null;
	completedAt: string | null;
	customer: number | null;
	paymentMethod: string;
	paymentTitle: string;
	total: string;
	subtotal: string;
	discountTotal: string;
	shippingTotal: string;
	taxTotal: string;
	billing: {
		first_name: string;
		last_name: string;
		company: string;
		address_1: string;
		address_2: string;
		city: string;
		state: string;
		postcode: string;
		country: string;
		email: string | null;
		phone: string | null;
	};
	shipping: {
		first_name: string;
		last_name: string;
		company: string;
		address_1: string;
		address_2: string;
		city: string;
		state: string;
		postcode: string;
		country: string;
		email: string | null;
		phone: string | null;
	};
	lines: {
		id: number;
		name: string;
		product: number;
		variation: number | null;
		quantity: number;
		subtotal: string;
		subtotalTax: string;
		total: string;
		totalTax: string;
		taxes: unknown;
	}[];
	shippingLines: {
		id: number;
		name: string;
		method_id: string;
		instance_id: string;
		total: string;
		total_tax: string;
		taxes: unknown;
	}[];
	taxLines: {
		id: number;
		name: string;
		rate_id: number;
		label: string;
		compound: boolean;
		tax_total: string;
		shipping_tax_total: string;
	}[];
	feeLines: {
		id: number;
		name: string;
		total: string;
		total_tax: string;
		taxes: unknown;
	}[];
	couponLines: {
		id: number;
		name: string;
		code: string;
		discount: string;
		discount_tax: string;
	}[];
	refunds: {
		id: number;
		amount: string;
		reason: string;
		createdAt: string;
	}[];
}
export type OrderInsert = never;
export type OrderPatch = never;
export interface OrderWith {}
export interface OrderFilter {
	id?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	status?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	currency?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	precision?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	createdAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	updatedAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	paidAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	completedAt?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		isNull?: true;
		isNotNull?: true;
	};
	customer?: number | null | {
		eq?: number | null;
		ne?: number | null;
		gt?: number;
		gte?: number;
		lt?: number;
		lte?: number;
		in?: number[];
		notIn?: number[];
		isNull?: true;
		isNotNull?: true;
	};
	paymentMethod?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	paymentTitle?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	total?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	subtotal?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	discountTotal?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	shippingTotal?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	taxTotal?: string | null | {
		eq?: string | null;
		ne?: string | null;
		gt?: string;
		gte?: string;
		lt?: string;
		lte?: string;
		in?: string[];
		notIn?: string[];
		like?: string;
		ilike?: string;
		notLike?: string;
		notIlike?: string;
		isNull?: true;
		isNotNull?: true;
	};
	billing?: {
		first_name: string;
		last_name: string;
		company: string;
		address_1: string;
		address_2: string;
		city: string;
		state: string;
		postcode: string;
		country: string;
		email: string | null;
		phone: string | null;
	} | null | {
		eq?: {
			first_name: string;
			last_name: string;
			company: string;
			address_1: string;
			address_2: string;
			city: string;
			state: string;
			postcode: string;
			country: string;
			email: string | null;
			phone: string | null;
		} | null;
		ne?: {
			first_name: string;
			last_name: string;
			company: string;
			address_1: string;
			address_2: string;
			city: string;
			state: string;
			postcode: string;
			country: string;
			email: string | null;
			phone: string | null;
		} | null;
		isNull?: true;
		isNotNull?: true;
	};
	shipping?: {
		first_name: string;
		last_name: string;
		company: string;
		address_1: string;
		address_2: string;
		city: string;
		state: string;
		postcode: string;
		country: string;
		email: string | null;
		phone: string | null;
	} | null | {
		eq?: {
			first_name: string;
			last_name: string;
			company: string;
			address_1: string;
			address_2: string;
			city: string;
			state: string;
			postcode: string;
			country: string;
			email: string | null;
			phone: string | null;
		} | null;
		ne?: {
			first_name: string;
			last_name: string;
			company: string;
			address_1: string;
			address_2: string;
			city: string;
			state: string;
			postcode: string;
			country: string;
			email: string | null;
			phone: string | null;
		} | null;
		isNull?: true;
		isNotNull?: true;
	};
	lines?: {
		id: number;
		name: string;
		product: number;
		variation: number | null;
		quantity: number;
		subtotal: string;
		subtotalTax: string;
		total: string;
		totalTax: string;
		taxes: unknown;
	}[] | null | {
		eq?: {
			id: number;
			name: string;
			product: number;
			variation: number | null;
			quantity: number;
			subtotal: string;
			subtotalTax: string;
			total: string;
			totalTax: string;
			taxes: unknown;
		}[] | null;
		ne?: {
			id: number;
			name: string;
			product: number;
			variation: number | null;
			quantity: number;
			subtotal: string;
			subtotalTax: string;
			total: string;
			totalTax: string;
			taxes: unknown;
		}[] | null;
		isNull?: true;
		isNotNull?: true;
	};
	shippingLines?: {
		id: number;
		name: string;
		method_id: string;
		instance_id: string;
		total: string;
		total_tax: string;
		taxes: unknown;
	}[] | null | {
		eq?: {
			id: number;
			name: string;
			method_id: string;
			instance_id: string;
			total: string;
			total_tax: string;
			taxes: unknown;
		}[] | null;
		ne?: {
			id: number;
			name: string;
			method_id: string;
			instance_id: string;
			total: string;
			total_tax: string;
			taxes: unknown;
		}[] | null;
		isNull?: true;
		isNotNull?: true;
	};
	taxLines?: {
		id: number;
		name: string;
		rate_id: number;
		label: string;
		compound: boolean;
		tax_total: string;
		shipping_tax_total: string;
	}[] | null | {
		eq?: {
			id: number;
			name: string;
			rate_id: number;
			label: string;
			compound: boolean;
			tax_total: string;
			shipping_tax_total: string;
		}[] | null;
		ne?: {
			id: number;
			name: string;
			rate_id: number;
			label: string;
			compound: boolean;
			tax_total: string;
			shipping_tax_total: string;
		}[] | null;
		isNull?: true;
		isNotNull?: true;
	};
	feeLines?: {
		id: number;
		name: string;
		total: string;
		total_tax: string;
		taxes: unknown;
	}[] | null | {
		eq?: {
			id: number;
			name: string;
			total: string;
			total_tax: string;
			taxes: unknown;
		}[] | null;
		ne?: {
			id: number;
			name: string;
			total: string;
			total_tax: string;
			taxes: unknown;
		}[] | null;
		isNull?: true;
		isNotNull?: true;
	};
	couponLines?: {
		id: number;
		name: string;
		code: string;
		discount: string;
		discount_tax: string;
	}[] | null | {
		eq?: {
			id: number;
			name: string;
			code: string;
			discount: string;
			discount_tax: string;
		}[] | null;
		ne?: {
			id: number;
			name: string;
			code: string;
			discount: string;
			discount_tax: string;
		}[] | null;
		isNull?: true;
		isNotNull?: true;
	};
	refunds?: {
		id: number;
		amount: string;
		reason: string;
		createdAt: string;
	}[] | null | {
		eq?: {
			id: number;
			amount: string;
			reason: string;
			createdAt: string;
		}[] | null;
		ne?: {
			id: number;
			amount: string;
			reason: string;
			createdAt: string;
		}[] | null;
		isNull?: true;
		isNotNull?: true;
	};
	AND?: OrderFilter[];
	OR?: OrderFilter[];
	NOT?: OrderFilter;
}
export type OrderOrder = Partial<Record<"id" | "status" | "currency" | "precision" | "createdAt" | "updatedAt" | "paidAt" | "completedAt" | "customer" | "paymentMethod" | "paymentTitle" | "total" | "subtotal" | "discountTotal" | "shippingTotal" | "taxTotal", "asc" | "desc">> | string;
export interface Settings {
	siteTitle: string;
	announcement: string | null;
	mailFrom: string | null;
	replyTo: string | null;
	links: {
		label: string;
		href: string;
	}[] | null;
}
export interface SettingsPatch {
	siteTitle?: string;
	announcement?: string | null;
	mailFrom?: string | null;
	replyTo?: string | null;
	links?: {
		label: string;
		href: string;
	}[] | null;
}
export interface BookSeatsInput {
	eventId: number;
	seats: number;
	allergies?: string;
}
export type BookSeatsOutput = {
	code: string;
};
export interface ContactKitchenInput {
	email: string;
	message: string;
}
export type ContactKitchenOutput = {
	delivery: string;
};
export interface CancelSeatsInput {
	eventId: number;
}
export type CancelSeatsOutput = {
	seats: number;
};
export type UpcomingInput = Record<string, never>;
export type UpcomingOutput = {
	rows: Event[];
};
export interface CloseRegistrationInput {
	id: number;
}
export type CloseRegistrationOutput = {
	id: number;
};
export interface ScheduleAgainInput {
	id: number;
}
export type ScheduleAgainOutput = {
	id: number;
	startsOn: string;
};
export interface CancelWorkshopInput {
	id: number;
}
export type CancelWorkshopOutput = {
	id: number;
};
export interface RestoreWorkshopInput {
	id: number;
}
export type RestoreWorkshopOutput = {
	id: number;
};
export interface PurgeWorkshopInput {
	id: number;
}
export type PurgeWorkshopOutput = {
	id: number;
};
export interface WorkshopHistoryInput {
	id: number;
}
export type WorkshopHistoryOutput = {
	count: number;
	firstTitle: string | null;
	diff: {
		from: number;
		to: number;
		changes: ({
			field: string;
			from?: unknown;
			to?: unknown;
		})[];
	} | null;
};
export interface RevertWorkshopInput {
	id: number;
	version: number;
}
export type RevertWorkshopOutput = {
	id: number;
	title: string;
};
export interface PostAnnouncementInput {
	announcement?: string;
}
export type PostAnnouncementOutput = {
	announcement: string | null;
};
export interface DraftRecapInput {
	id: number;
}
export type DraftRecapOutput = {
	postId: number;
	title: string | "";
	status: string | "";
};
export type CheckScheduleInput = Record<string, never>;
export type CheckScheduleOutput = {
	workshops: number;
	timezone: string;
	hasWorkshops: boolean;
};
export type ScheduleMetricsInput = Record<string, never>;
export type ScheduleMetricsOutput = {
	workshops: number;
	timezone: string;
	hasWorkshops: boolean;
};
export interface Collections {
	events: {
		row: Event;
		insert: EventInsert;
		patch: EventPatch;
		with: EventWith;
		filter: EventFilter;
		order: EventOrder;
	};
	posts: {
		row: Post;
		insert: PostInsert;
		patch: PostPatch;
		with: PostWith;
		filter: PostFilter;
		order: PostOrder;
	};
	recipes: {
		row: Recipe;
		insert: RecipeInsert;
		patch: RecipePatch;
		with: RecipeWith;
		filter: RecipeFilter;
		order: RecipeOrder;
	};
	handbook: {
		row: Handbook;
		insert: HandbookInsert;
		patch: HandbookPatch;
		with: HandbookWith;
		filter: HandbookFilter;
		order: HandbookOrder;
	};
	registrations: {
		row: Registration;
		insert: RegistrationInsert;
		patch: RegistrationPatch;
		with: RegistrationWith;
		filter: RegistrationFilter;
		order: RegistrationOrder;
	};
	activity: {
		row: Activity;
		insert: ActivityInsert;
		patch: ActivityPatch;
		with: ActivityWith;
		filter: ActivityFilter;
		order: ActivityOrder;
	};
	members: {
		row: Member;
		insert: MemberInsert;
		patch: MemberPatch;
		with: MemberWith;
		filter: MemberFilter;
		order: MemberOrder;
	};
	topics: {
		row: Topic;
		insert: TopicInsert;
		patch: TopicPatch;
		with: TopicWith;
		filter: TopicFilter;
		order: TopicOrder;
	};
	supplies: {
		row: Supply;
		insert: SupplyInsert;
		patch: SupplyPatch;
		with: SupplyWith;
		filter: SupplyFilter;
		order: SupplyOrder;
	};
	supplyCategories: {
		row: SupplyCategory;
		insert: SupplyCategoryInsert;
		patch: SupplyCategoryPatch;
		with: SupplyCategoryWith;
		filter: SupplyCategoryFilter;
		order: SupplyCategoryOrder;
	};
	supplyTags: {
		row: SupplyTag;
		insert: SupplyTagInsert;
		patch: SupplyTagPatch;
		with: SupplyTagWith;
		filter: SupplyTagFilter;
		order: SupplyTagOrder;
	};
	orders: {
		row: Order;
		insert: OrderInsert;
		patch: OrderPatch;
		with: OrderWith;
		filter: OrderFilter;
		order: OrderOrder;
	};
}
export interface Globals {
	settings: {
		row: Settings;
		patch: SettingsPatch;
	};
}
export interface Actions {
	bookSeats: {
		input: BookSeatsInput;
		output: BookSeatsOutput;
	};
	contactKitchen: {
		input: ContactKitchenInput;
		output: ContactKitchenOutput;
	};
	cancelSeats: {
		input: CancelSeatsInput;
		output: CancelSeatsOutput;
	};
	upcoming: {
		input: UpcomingInput;
		output: UpcomingOutput;
	};
	closeRegistration: {
		input: CloseRegistrationInput;
		output: CloseRegistrationOutput;
	};
	scheduleAgain: {
		input: ScheduleAgainInput;
		output: ScheduleAgainOutput;
	};
	cancelWorkshop: {
		input: CancelWorkshopInput;
		output: CancelWorkshopOutput;
	};
	restoreWorkshop: {
		input: RestoreWorkshopInput;
		output: RestoreWorkshopOutput;
	};
	purgeWorkshop: {
		input: PurgeWorkshopInput;
		output: PurgeWorkshopOutput;
	};
	workshopHistory: {
		input: WorkshopHistoryInput;
		output: WorkshopHistoryOutput;
	};
	revertWorkshop: {
		input: RevertWorkshopInput;
		output: RevertWorkshopOutput;
	};
	postAnnouncement: {
		input: PostAnnouncementInput;
		output: PostAnnouncementOutput;
	};
	draftRecap: {
		input: DraftRecapInput;
		output: DraftRecapOutput;
	};
	checkSchedule: {
		input: CheckScheduleInput;
		output: CheckScheduleOutput;
	};
	scheduleMetrics: {
		input: ScheduleMetricsInput;
		output: ScheduleMetricsOutput;
	};
}
export declare const SCHEMA: {
	readonly name: "kitchen-sink";
	readonly collections: {
		readonly events: {
			readonly path: "events";
			readonly primaryKey: "id";
			readonly drafts: true;
			readonly revisions: true;
			readonly softDelete: true;
			readonly localized: false;
			readonly relations: {
				readonly registrations: {
					readonly kind: "many";
					readonly collection: "registrations";
					readonly optional: false;
				};
				readonly activity: {
					readonly kind: "many";
					readonly collection: "activity";
					readonly optional: false;
				};
			};
			readonly media: readonly ["poster", "gallery"];
			readonly mediaList: readonly ["gallery"];
			readonly files: readonly ["handout"];
			readonly showIf: {
				readonly demoUrl: {
					readonly when: {
						readonly format: "demo";
					};
				};
			};
		};
		readonly posts: {
			readonly path: "posts";
			readonly primaryKey: "id";
			readonly drafts: true;
			readonly revisions: true;
			readonly softDelete: true;
			readonly localized: false;
			readonly relations: {};
			readonly media: readonly [];
			readonly mediaList: readonly [];
			readonly files: readonly [];
		};
		readonly recipes: {
			readonly path: "recipes";
			readonly primaryKey: "id";
			readonly drafts: true;
			readonly revisions: true;
			readonly softDelete: true;
			readonly localized: false;
			readonly relations: {
				readonly topicIds: {
					readonly kind: "many";
					readonly collection: "topics";
					readonly optional: false;
				};
			};
			readonly media: readonly [];
			readonly mediaList: readonly [];
			readonly files: readonly [];
		};
		readonly handbook: {
			readonly path: "handbook";
			readonly primaryKey: "id";
			readonly drafts: true;
			readonly revisions: true;
			readonly softDelete: true;
			readonly localized: false;
			readonly relations: {
				readonly parent: {
					readonly kind: "one";
					readonly collection: "handbook";
					readonly optional: true;
				};
				readonly children: {
					readonly kind: "many";
					readonly collection: "handbook";
					readonly optional: false;
				};
			};
			readonly media: readonly [];
			readonly mediaList: readonly [];
			readonly files: readonly [];
		};
		readonly registrations: {
			readonly path: "registrations";
			readonly primaryKey: "id";
			readonly drafts: false;
			readonly revisions: true;
			readonly softDelete: true;
			readonly localized: false;
			readonly relations: {
				readonly eventId: {
					readonly kind: "one";
					readonly collection: "events";
					readonly optional: false;
				};
				readonly memberId: {
					readonly kind: "one";
					readonly collection: "members";
					readonly optional: false;
				};
			};
			readonly media: readonly [];
			readonly mediaList: readonly [];
			readonly files: readonly [];
		};
		readonly activity: {
			readonly path: "activity";
			readonly primaryKey: "id";
			readonly drafts: false;
			readonly revisions: true;
			readonly softDelete: true;
			readonly localized: false;
			readonly relations: {
				readonly eventId: {
					readonly kind: "one";
					readonly collection: "events";
					readonly optional: true;
				};
			};
			readonly media: readonly [];
			readonly mediaList: readonly [];
			readonly files: readonly [];
		};
		readonly members: {
			readonly path: "members";
			readonly primaryKey: "id";
			readonly drafts: false;
			readonly revisions: false;
			readonly softDelete: false;
			readonly localized: false;
			readonly relations: {
				readonly registrations: {
					readonly kind: "many";
					readonly collection: "registrations";
					readonly optional: false;
				};
			};
			readonly media: readonly [];
			readonly mediaList: readonly [];
			readonly files: readonly [];
		};
		readonly topics: {
			readonly path: "topics";
			readonly primaryKey: "id";
			readonly drafts: false;
			readonly revisions: false;
			readonly softDelete: false;
			readonly localized: false;
			readonly relations: {
				readonly recipes: {
					readonly kind: "many";
					readonly collection: "recipes";
					readonly optional: false;
				};
			};
			readonly media: readonly [];
			readonly mediaList: readonly [];
			readonly files: readonly [];
		};
		readonly supplies: {
			readonly path: "supplies";
			readonly primaryKey: "id";
			readonly drafts: false;
			readonly revisions: false;
			readonly softDelete: false;
			readonly localized: false;
			readonly relations: {
				readonly categories: {
					readonly kind: "many";
					readonly collection: "supplyCategories";
					readonly optional: false;
				};
				readonly tags: {
					readonly kind: "many";
					readonly collection: "supplyTags";
					readonly optional: false;
				};
			};
			readonly media: readonly ["images"];
			readonly mediaList: readonly ["images"];
			readonly files: readonly [];
		};
		readonly supplyCategories: {
			readonly path: "supply_categories";
			readonly primaryKey: "id";
			readonly drafts: false;
			readonly revisions: false;
			readonly softDelete: false;
			readonly localized: false;
			readonly relations: {
				readonly supplies: {
					readonly kind: "many";
					readonly collection: "supplies";
					readonly optional: false;
				};
			};
			readonly media: readonly [];
			readonly mediaList: readonly [];
			readonly files: readonly [];
		};
		readonly supplyTags: {
			readonly path: "supply_tags";
			readonly primaryKey: "id";
			readonly drafts: false;
			readonly revisions: false;
			readonly softDelete: false;
			readonly localized: false;
			readonly relations: {
				readonly supplies: {
					readonly kind: "many";
					readonly collection: "supplies";
					readonly optional: false;
				};
			};
			readonly media: readonly [];
			readonly mediaList: readonly [];
			readonly files: readonly [];
		};
		readonly orders: {
			readonly path: "orders";
			readonly primaryKey: "id";
			readonly drafts: false;
			readonly revisions: false;
			readonly softDelete: false;
			readonly localized: false;
			readonly relations: {};
			readonly media: readonly [];
			readonly mediaList: readonly [];
			readonly files: readonly [];
		};
	};
	readonly globals: readonly ["settings"];
	readonly actions: readonly ["bookSeats", "contactKitchen", "cancelSeats", "upcoming", "closeRegistration", "scheduleAgain", "cancelWorkshop", "restoreWorkshop", "purgeWorkshop", "workshopHistory", "revertWorkshop", "postAnnouncement", "draftRecap", "checkSchedule", "scheduleMetrics"];
	readonly buckets: readonly ["handouts"];
};
export type SchemaDescriptor = typeof SCHEMA;
type Descriptor = typeof SCHEMA;
type CollectionDescriptors = Descriptor["collections"];
/** Schema keys of every collection (`client.<key>`). */
export type CollectionKey = keyof Collections & keyof CollectionDescriptors & string;
type Row<K extends CollectionKey> = Collections[K]["row"];
type Rels<K extends CollectionKey> = CollectionDescriptors[K]["relations"];
type IdOf<K extends CollectionKey> = Row<K>[CollectionDescriptors[K]["primaryKey"] & keyof Row<K>];
/** `with` keys that load something (`false`/`undefined` entries load nothing). */
type Requested<W> = { [P in keyof W]-?: W[P] extends false | undefined | null ? never : P }[keyof W];
type NestedWith<V> = V extends {
	with: infer W;
} ? W : {};
type RelationValue<
	R,
	V
> = R extends {
	kind: "one";
	collection: infer T extends CollectionKey;
	optional: infer O;
} ? RowWith<T, NestedWith<V>> | (O extends false ? never : null) : R extends {
	kind: "many";
	collection: infer T extends CollectionKey;
} ? RowWith<T, NestedWith<V>>[] : never;
type WithValue<
	K extends CollectionKey,
	P,
	V
> = P extends keyof Rels<K> ? RelationValue<Rels<K>[P], V> : P extends CollectionDescriptors[K]["mediaList"][number] ? Media[] | (null extends Row<K>[P & keyof Row<K>] ? null : never) : P extends CollectionDescriptors[K]["media"][number] ? Media | null : P extends CollectionDescriptors[K]["files"][number] ? PrivateFile | number | (null extends Row<K>[P & keyof Row<K>] ? null : never) : never;
/**
* A row of collection `K` loaded with `with: W`: `one` relations become the related row (or null when
* optional), `many` relations a list, and requested media columns are replaced in place by media rows.
* Requested file columns become the file row when the bucket's read rule admits the viewer, else stay the id.
*/
export type RowWith<
	K extends CollectionKey,
	W
> = [Requested<W>] extends [never] ? Row<K> : ([Requested<W> & keyof Row<K>] extends [never] ? Row<K> : Omit<Row<K>, Requested<W> & keyof Row<K>>) & { -readonly [P in Exclude<Requested<W>, OptionalKeys<Row<K>>>]: WithValue<K, P, W[P]> } & { -readonly [P in Extract<Requested<W>, OptionalKeys<Row<K>>>]?: WithValue<K, P, W[P]> };
type OptionalKeys<T> = { [P in keyof T]-?: {} extends Pick<T, P> ? P : never }[keyof T] & PropertyKey;
/** Rejects `with` keys that are neither relations nor media columns (`with: { autor: true }`). */
type ExactWith<
	K extends CollectionKey,
	W
> = W & { [P in Exclude<keyof W, keyof Collections[K]["with"]>]: never };
type StatusQuery<K extends CollectionKey> = CollectionDescriptors[K]["drafts"] extends true ? {
	/** Shorthand for `where: { status }` (ANDed with `where`). */ status?: Status;
} : {};
type LocaleQuery<K extends CollectionKey> = CollectionDescriptors[K]["localized"] extends true ? {
	/** Rows in this locale, falling back to the default locale per translation group; `"*"` = every row. Default: the default locale. */ locale?: Locale | "*";
} : {};
export type ListQuery<
	K extends CollectionKey,
	W = Collections[K]["with"]
> = {
	where?: Collections[K]["filter"];
	/** `{ createdAt: "desc", title: "asc" }` or the wire form `"-createdAt,title"`. */
	orderBy?: Collections[K]["order"];
	limit?: number;
	offset?: number;
	with?: W;
	/** Soft-delete filter: `"exclude"` (default), `"include"`, or `"only"`. Non-default modes require `restore` or `delete` access. */
	deleted?: "exclude" | "include" | "only";
} & StatusQuery<K> & LocaleQuery<K>;
/**
* A read. Await it for one answer, or `subscribe` to keep it current: the callback gets the result
* now and again whenever it changes, always as this client's visitor reads it.
*/
export interface Query<T> extends Promise<T> {
	/** The same for the same call with the same arguments: what a cache or a hook keys on. */
	readonly key: string;
	/**
	* Calls `onData` with the result, and again each time it changes. `onError` gets the problem of a
	* read the server refuses (a `get` whose row is missing or unreadable is a 404) and network
	* failures; the subscription continues. `seed` is an answer rendered on the server
	* (`TypedPress\Live\Module::evaluate`): its data is delivered at once and its token sent with the
	* first request. Returns a function that ends the subscription.
	*/
	subscribe(onData: (data: T) => void, onError?: (error: TypedPressError) => void, seed?: {
		token: string;
		data: T;
	}): () => void;
	/**
	* Shows subscribers `update(current)` at once, before the server has answered a write. Call
	* `settle()` when the write succeeded (the change stays until the server's next answer replaces
	* it) or `rollback()` when it failed (subscribers get the server's data back).
	*/
	optimistic(update: (current: T) => T): {
		settle(): void;
		rollback(): void;
	};
}
export interface CollectionClient<K extends CollectionKey> {
	list<const W extends Collections[K]["with"] = Extract<{}, Collections[K]["with"]>>(q?: ListQuery<K, ExactWith<K, W>>): Query<RowWith<K, W>[]>;
	/**
	* One page and the `cursor` of the next (null on the last page): pass it back as `cursor`. A page
	* with `offset` can be awaited but not subscribed to (`subscribe` throws): subscribe by `cursor`.
	*/
	paginate<const W extends Collections[K]["with"] = Extract<{}, Collections[K]["with"]>>(q?: ListQuery<K, ExactWith<K, W>> & {
		cursor?: string | null;
	}): Query<{
		rows: RowWith<K, W>[];
		cursor: string | null;
	}>;
	/** With `locale`, the row of `id`'s translation group in that locale (or its fallback). */
	get<const W extends Collections[K]["with"] = Extract<{}, Collections[K]["with"]>>(id: IdOf<K>, q?: {
		with?: ExactWith<K, W>;
	} & LocaleQuery<K>): Query<RowWith<K, W>>;
	/** How many rows `list` with this filter would return without a limit. */
	count(q?: {
		where?: Collections[K]["filter"];
	} & StatusQuery<K> & LocaleQuery<K>): Query<number>;
	create(data: Collections[K]["insert"]): Promise<Row<K>>;
	/**
	* On a collection, add `_rev` (from a read) to the patch to refuse the update when the row changed
	* since: a 409 `stale_revision`. While someone else has the row open in the editor the update is a
	* 409 `locked`; `takeover: true` takes over their edit lock.
	*/
	update(id: IdOf<K>, patch: Collections[K]["patch"] & WriteConditions): Promise<Row<K>>;
	delete(id: IdOf<K>, options?: WriteConditions): Promise<Row<K>>;
	/** Restores a soft-deleted row (and any cascade-deleted children sharing its deletion group). */
	restore(id: IdOf<K>): Promise<Row<K>>;
	/** Permanently removes the row from storage (default access `"system"`). */
	purge(id: IdOf<K>): Promise<Row<K>>;
	/** `list(q).subscribe(onRows, onError)`. Returns a function that stops watching. */
	watch<const W extends Collections[K]["with"] = Extract<{}, Collections[K]["with"]>>(q: ListQuery<K, ExactWith<K, W>> | undefined, onRows: (rows: RowWith<K, W>[]) => void, onError?: (error: TypedPressError) => void): () => void;
	revisions: {
		/** Newest first. Needs `update` access on the row. */
		list(id: IdOf<K>): Promise<RevisionSummary[]>;
		get(id: IdOf<K>, revisionId: number): Promise<Row<K>>;
		/** Diffs two revisions (by version or revision id) as this viewer may read them. */
		diff(id: IdOf<K>, fromVersion: number, toVersion: number): Promise<RevisionDiff<Row<K>>>;
		/** Runs the update pipeline with the revision as the patch; returns the updated row. */
		restore(id: IdOf<K>, revisionId: number): Promise<Row<K>>;
	};
	/**
	* Save `patch` as the item's staged version: the live row stays as it is until `promote`. One
	* staged version per item, shared by everyone who may update it; each call adds to it. Post
	* kinds only. Every staged call needs `update` access on the row, and answers 404 without it.
	*/
	stage(id: IdOf<K>, patch: Collections[K]["patch"] & WriteConditions): Promise<StagedVersion<K>>;
	/** The staged version: the row as it would be once promoted. 404 when nothing is staged. */
	staged(id: IdOf<K>): Promise<StagedVersion<K>>;
	/** The staged fields whose value differs from the live row, with both values. */
	compare(id: IdOf<K>): Promise<StagedComparison<K>>;
	/**
	* Make the staged version live, as an update of the row by the caller. With `at` (a future ISO
	* time) the promote is scheduled instead, and `at: null` cancels a scheduled one; both return
	* the staged version.
	*/
	promote(id: IdOf<K>, options?: WriteConditions): Promise<Row<K>>;
	promote(id: IdOf<K>, options: {
		at: string | null;
	} & WriteConditions): Promise<StagedVersion<K>>;
	/** End the staged version. It stays in the row's revisions, as `revision`. */
	discard(id: IdOf<K>, options?: WriteConditions): Promise<{
		revision: number;
	}>;
	/** A signed link to the staged version that works without signing in for `ttl` seconds (an hour by default). */
	previewLink(id: IdOf<K>, options?: {
		ttl?: number;
	}): Promise<StagedPreview>;
}
/** A staged version: the row once promoted, the fields that are staged, who staged last. */
/**
* A write's conditions on a collection's row: `_rev`, the token a read returned (the row's own, or a
* staged version's for stage, promote and discard), refuses the write if the row changed since;
* `takeover` takes over another user's edit lock.
*/
export interface WriteConditions {
	_rev?: string;
	takeover?: boolean;
}
export interface StagedVersion<K extends CollectionKey> {
	/** The revision holding it. */
	id: number;
	row: Row<K>;
	fields: (keyof Row<K> & string)[];
	by: {
		id: number;
		name: string;
	} | null;
	at: string;
	/** When a scheduled promote runs, or null. */
	promoteAt: string | null;
	/** The staged version's token, for the next stage, promote or discard. */
	_rev: string;
	/** Why the last scheduled promote did not run. */
	error?: {
		code: string;
		detail: string;
		at: string;
	};
}
export interface StagedComparison<K extends CollectionKey> extends Omit<StagedVersion<K>, "row" | "fields"> {
	changes: { [F in keyof Row<K> & string]: {
		field: F;
		live: Row<K>[F];
		staged: Row<K>[F];
	} }[keyof Row<K> & string][];
}
export interface StagedPreview {
	token: string;
	expiresAt: string;
	/** Reads the staged version without signing in. */
	api: string;
	/** The item's own page showing the staged version, when the kind has a public URL. */
	url: string | null;
}
export interface MediaQuery {
	where?: Record<string, unknown>;
	orderBy?: Record<string, "asc" | "desc"> | string;
	limit?: number;
	offset?: number;
}
export interface MediaClient {
	list(q?: MediaQuery): Promise<Media[]>;
	get(id: number): Promise<Media>;
	/** `multipart/form-data` with fields `file` and `alt`. */
	upload(file: Blob, opts?: {
		alt?: string;
		filename?: string;
	}): Promise<Media>;
	/** Editors only. A new `focal` point (0..1 from the top-left) regenerates every `cover` size. */
	update(id: number, patch: {
		alt?: string | null;
		focal?: {
			x: number;
			y: number;
		} | null;
	}): Promise<Media>;
	delete(id: number): Promise<Media>;
}
export interface FileBucketClient {
	/** `multipart/form-data` field `file`; the bucket's `accept`, `maxBytes` and `create` rule apply. */
	upload(file: Blob, opts?: {
		filename?: string;
	}): Promise<PrivateFile>;
	/** The row with a fresh `url`: a signed download link valid for 5 minutes. */
	get(id: number): Promise<PrivateFile>;
	delete(id: number): Promise<PrivateFile>;
}
export type FilesClient = { readonly [B in Descriptor["buckets"][number]]: FileBucketClient };
export type ActionsClient = { [A in keyof Actions]: {} extends Actions[A]["input"] ? (input?: Actions[A]["input"]) => Promise<Actions[A]["output"]> : (input: Actions[A]["input"]) => Promise<Actions[A]["output"]> };
export type GlobalsClient = { [G in keyof Globals]: {
	get(): Promise<Globals[G]["row"]>;
	update(patch: Globals[G]["patch"]): Promise<Globals[G]["row"]>;
} };
/** The tokens a signed-in client holds (`auth.session()`). */
export interface Session {
	accessToken: string;
	refreshToken: string;
	/** Epoch milliseconds. */
	expiresAt: number;
}
export interface AuthClient {
	/** Redirects to `<issuer>/authorize` (PKCE S256); the promise never settles. */
	signIn(opts?: {
		returnTo?: string;
	}): Promise<never>;
	/** On the redirect URI: exchanges `?code` at `<issuer>/token`, keeps the tokens, returns the user. */
	handleCallback(): Promise<User>;
	/** Starts a guest session (`role: "guest"`) and keeps its tokens. */
	guest(): Promise<User>;
	/** Sends the current guest to the issuer's screens to upgrade in place; never settles. */
	upgrade(): Promise<never>;
	/** The signed-in user (with `member`, the auth collection row, when the app has one), or null. */
	me(): Promise<User | null>;
	/** Revokes the session and forgets the tokens; also signs out at the issuer when `returnTo` is given. */
	signOut(opts?: {
		returnTo?: string;
	}): Promise<void>;
	/** The held tokens, for server-side callers that forward them; null when signed out. */
	session(): Session | null;
}
export type Client = { readonly [K in CollectionKey]: CollectionClient<K> } & {
	readonly media: MediaClient;
	readonly files: FilesClient;
	readonly actions: ActionsClient;
	readonly globals: GlobalsClient;
	readonly auth: AuthClient;
	/** `GET /wp-json/typedpress/v1`: the app's schema (kinds, fields, globals, actions), as `typedpress pull` reads it. */
	schema(): Promise<unknown>;
};
/** One committed transaction's changes, as the runtime's `tp_realtime_changes` hook sends them. */
export interface ChangeNotice {
	id: string;
	at: string;
	/** `ids: null` means "some rows changed": too many to list, or ids withheld from a shared room. */
	changes: {
		collection: string;
		ops: ("create" | "update" | "delete")[];
		ids: (string | number)[] | null;
	}[];
}
/**
* Delivers change notices from the host (Cast, a WebSocket relay, Pusher, ...). TypedPress ships none.
* `connect` starts delivery and returns a stop function; call `status("live")` once notices flow
* and `status("down")` when they stop, so the client polls meanwhile.
*/
export interface RealtimeTransport {
	connect(deliver: (notice: ChangeNotice) => void, status: (state: "live" | "down") => void): () => void;
}
export interface RealtimeOptions {
	/** The transport. Default: the one a host registered with `registerRealtimeTransport`; `false` = polling only. */
	transport?: RealtimeTransport | false;
	/**
	* How often subscriptions ask the server while no transport is live. Default: adaptive, between
	* the bounds the server gives (2 s after a change, growing by half up to 30 s while nothing
	* changes, each wait jittered by 20%). `{ min, max }` sets the bounds, a number a fixed interval,
	* and `false` turns polling off. A hidden tab doesn't poll; it asks once when it is shown again.
	*/
	pollMs?: number | false | {
		min?: number;
		max?: number;
	};
	/**
	* A slow safety refresh while the transport is live, covering a notice the transport lost.
	* Default 60000 with up to ±20% jitter per refresh; `false` turns it off.
	*/
	livePollMs?: number | false;
}
/** For host scripts: every client on this page, created before or after, uses this transport by default. */
export declare function registerRealtimeTransport(transport: RealtimeTransport): void;
export interface ClientOptions {
	/** Base URL including the API prefix: "https://x.test/wp-json/typedpress/v1". Default: `/wp-json/typedpress/v1` on the page's origin. */
	url?: string;
	/** WordPress cookie auth: the REST nonce; sent as `X-WP-Nonce` with `credentials: "same-origin"`. */
	nonce?: string;
	fetch?: typeof fetch;
	/** Extra headers on every request (e.g. `Authorization: Basic ...` for application passwords). They win over the above. */
	headers?: Record<string, string>;
	/** Frontend sign-in (design §6.2). */
	auth?: AuthOptions;
	/** Live updates for `subscribe` and `watch`. */
	realtime?: RealtimeOptions;
}
export interface AuthOptions {
	/** The typedpress-identity OIDC issuer (`https://x.test/identity`). Default: `issuer` from `GET /auth/config`. */
	issuer?: string;
	/** The registered application's client id. */
	clientId?: string;
	/** Where the issuer sends the browser back (the page that calls `handleCallback()`). */
	redirectUri?: string;
	/** Where tokens live: in memory (default) or `sessionStorage` under `typedpress.auth:<base url>`. */
	storage?: "memory" | "session";
	/** A write rejected as unauthenticated while signed out starts a guest session and is retried once. */
	guests?: boolean;
}
/** RFC 9457 problem document, as the runtime sends it. */
export interface Problem {
	type: string;
	title: string;
	status: number;
	code: string;
	detail: string;
	pointer?: string;
}
/** Thrown for every non-2xx response; the fields are the problem document. Network failures: status 0, code "network". */
export declare class TypedPressError extends Error {
	readonly status: number;
	readonly code: string;
	readonly detail: string;
	readonly title: string;
	readonly type: string;
	readonly pointer?: string;
	constructor(problem: Problem);
}
export declare function createClient(options?: ClientOptions): Client;
/**
* Runs `read` now and again whenever one of `collections` (schema keys) changes, while `client`'s
* transport is live, or on its polling interval otherwise. For reads a `subscribe` can't express:
* an action's result, a global. Returns a function that stops watching.
*/
export declare function watch<T>(client: Client, collections: readonly CollectionKey[], read: () => Promise<T>, onResult: (value: T) => void, onError?: (error: TypedPressError) => void): () => void;
/** Cart contracts and error-cart handling adapted from @woo/storefront-core (MIT),
* packages/core/src/contracts.ts and packages/core/src/transport.ts.
* Amounts here are exact decimal strings; the Store API's minor units are normalized on read.
*/
export interface WooCartItem {
	key: string;
	id: number;
	name: string;
	quantity: number;
	images: {
		id: number;
		src: string;
		thumbnail: string;
		alt: string;
	}[];
	prices: {
		price: string;
		currencyCode: string;
		currencyMinorUnit: number;
	};
	totals: {
		lineTotal: string;
		lineSubtotal: string;
	};
	variation: {
		attribute: string;
		value: string;
	}[];
}
export interface WooCartTotals {
	totalItems: string;
	totalPrice: string;
	totalShipping: string | null;
	totalDiscount: string;
	totalFees: string;
	totalTax: string;
	currencyCode: string;
	currencyMinorUnit: number;
}
export interface WooAddress {
	firstName?: string;
	lastName?: string;
	company?: string;
	address1?: string;
	address2?: string;
	city?: string;
	state?: string;
	postcode?: string;
	country?: string;
	email?: string;
	phone?: string;
}
export interface WooShippingPackage {
	packageId: number;
	name: string;
	rates: {
		id: string;
		name: string;
		price: string;
		selected: boolean;
		currencyCode: string;
		currencyMinorUnit: number;
	}[];
}
export interface WooCart {
	items: WooCartItem[];
	itemsCount: number;
	totals: WooCartTotals;
	needsShipping: boolean;
	shippingRates: WooShippingPackage[];
	billingAddress?: WooAddress;
	shippingAddress?: WooAddress;
	coupons: {
		code: string;
		totals: {
			totalDiscount: string;
		};
	}[];
	fees: {
		id: string;
		name: string;
		total: string;
		totalTax: string;
	}[];
}
/** The codes Woo 11.3.0's cart routes send for the requests this client makes (`StoreApi/Routes/V1/Cart*.php`,
* `Utilities/CartController.php`, `Utilities/QuantityLimits.php`, `Authentication.php`), with their statuses. */
export type WooStoreErrorCode = "invalid_quantity" | "rate_limit_exceeded" | "readonly_quantity" | "rest_invalid_param" | "woocommerce_rest_add_to_cart_error" | "woocommerce_rest_cart_error" | "woocommerce_rest_cart_invalid_key" | "woocommerce_rest_cart_invalid_parent_product" | "woocommerce_rest_cart_invalid_product" | "woocommerce_rest_cart_item_exists" | "woocommerce_rest_cart_missing_rate_id" | "woocommerce_rest_invalid_nonce" | "woocommerce_rest_invalid_variation_data" | "woocommerce_rest_missing_attributes" | "woocommerce_rest_missing_nonce" | "woocommerce_rest_missing_variation_data" | "woocommerce_rest_product_invalid_quantity" | "woocommerce_rest_product_not_purchasable" | "woocommerce_rest_product_out_of_stock" | "woocommerce_rest_product_partially_out_of_stock" | "woocommerce_rest_shipping_disabled" | "woocommerce_rest_unknown_server_error" | "woocommerce_rest_variation_id_from_variation_data";
export declare class WooStoreError extends Error {
	/** The string Woo sent, unchanged. Extensions and other Woo versions send codes outside `WooStoreErrorCode`.
	* `http_error` is this client's own: the answer was not a Woo error, such as a proxy's or a maintenance page. */
	readonly code: WooStoreErrorCode | (string & {});
	readonly status: number;
	/** The current cart. Woo sends it with a 409 only. */
	readonly cart: WooCart | undefined;
	/** `message` is plain text: Woo's HTML entities are already decoded. */
	constructor(code: WooStoreErrorCode | (string & {}), message: string, status: number, cart?: WooCart);
}
/** Exact conversion, including zero-decimal currencies. Never uses floating-point arithmetic. */
export declare function wooDecimal(minor: string, precision: number): string;
/** Woo's Store API cart. Browser only. */
export interface WooCartClient {
	get(): Promise<WooCart>;
	add(id: number, quantity?: number): Promise<WooCart>;
	/** Sets a line's quantity. Woo refuses a quantity of zero, so zero removes the line. */
	update(key: string, quantity: number): Promise<WooCart>;
	remove(key: string): Promise<WooCart>;
	updateCustomer(input: {
		billing?: WooAddress;
		shipping?: WooAddress;
	}): Promise<WooCart>;
	selectShipping(packageId: number, rateId: string): Promise<WooCart>;
	subscribe(listener: (cart: WooCart) => void): () => void;
}
interface CartTransportOptions {
	/** Woo Store API root. An absolute URL on another origin selects a token session. */
	base?: string;
	fetch?: typeof fetch;
}
type WooCheckoutAddress = Omit<WooAddress, "firstName" | "lastName" | "address1" | "address2"> & {
	first_name?: string;
	last_name?: string;
	address_1?: string;
	address_2?: string;
};
export interface WooCheckoutInput {
	billing_address: WooCheckoutAddress;
	shipping_address?: WooCheckoutAddress;
	payment_method: string;
	payment_data?: {
		key: string;
		value: string | boolean;
	}[];
	expected_total?: string;
	order_notes?: string;
	additional_fields?: Record<string, unknown>;
	extensions?: Record<string, unknown>;
}
export interface WooCheckoutResult {
	order_id: number;
	status: string;
	payment_result: {
		payment_status: string;
		redirect_url: string;
		payment_details: unknown;
	};
}
export interface WooTokenCartClient extends WooCartClient {
	/**
	* Places the order through Woo's Store API with the same token: no cookie at any step.
	* The cart is read again afterwards, so subscribers see the cart Woo emptied.
	*/
	checkout(input: WooCheckoutInput): Promise<WooCheckoutResult>;
	/**
	* The store's checkout page address that hands this cart to Woo's Checkout Block. Woo copies the
	* token's cart into a new cookie session there (`?session=`), so that page needs cookies.
	* The address must be on the Store API's origin: the token is a credential for this cart.
	*/
	checkoutLink(checkoutUrl: string): string;
}
/**
* `session: "cookie"` is Woo's session cookie and rotating nonce, the session the Checkout Block reads.
* `session: "token"` sends no cookie: Woo's `Cart-Token` header names the session and replaces the nonce.
* Left out, it is the cookie on the page's own origin and the token for a Store API on another one.
*/
export declare function createCartClient(options: CartTransportOptions & {
	session: "token";
}): WooTokenCartClient;
export declare function createCartClient(options?: CartTransportOptions & {
	session?: "cookie";
}): WooCartClient;
export declare const cart: WooCartClient;
export interface WooCartState {
	/** Woo's last confirmed cart with the pending writes' previews applied. Undefined until the first read answers. */
	cart: WooCart | undefined;
	/** True until the first read answers or fails. */
	loading: boolean;
	/** True while a write waits for Woo. */
	pending: boolean;
	/** True after an order succeeds until Woo confirms the cart again. */
	stale: boolean;
	/** The last failure. A refused write stays until the next write starts, so a later write that was
	* already queued and succeeds does not hide it. A failed read stays until Woo next answers with a cart. */
	error: Error | undefined;
}
export interface WooCartActions extends Pick<WooCartClient, "add" | "update" | "remove" | "selectShipping" | "updateCustomer"> {
	/** Reads the cart from Woo, after every write already queued. */
	refresh(): Promise<WooCart>;
}
/** A token cart's actions. `pending` is true while the order is placed, and the cart is Woo's afterwards. */
export interface WooTokenCartActions extends WooCartActions, Pick<WooTokenCartClient, "checkout"> {}
export interface WooCartStore<Actions extends WooCartActions = WooCartActions> {
	subscribe(changed: () => void): () => void;
	snapshot(): WooCartState;
	serverSnapshot(): WooCartState;
	actions: Actions;
}
/** One cart store per client, shared by React hooks and other browser subscribers. */
export declare function createCartStore(client: WooTokenCartClient): WooCartStore<WooTokenCartActions>;
export declare function createCartStore(client?: WooCartClient): WooCartStore;
export {};
