/**
 * Chat state returned by `getChatState()`.
 */
interface AgentsManagerChatState {
	isOpen: boolean;
	isDocked: boolean;
	floatingPosition: string;
}

/**
 * Public API shape exposed on `window.__agentsManagerActions`.
 */
interface AgentsManagerActions {
	getChatState: () => Promise< AgentsManagerChatState >;
	setChatOpen: ( isOpen: boolean ) => void;
	setChatDocked: ( isDocked: boolean ) => void;
	setChatEnabled: ( isEnabled: boolean ) => void;
	setChatCompactMode: ( isCompact: boolean ) => void;
	chatNavigate: import('react-router-dom').NavigateFunction;
	/**
	 * Set to `true` once Agents Manager has fully populated this object
	 * and dispatched the `agents-manager-ready` event. Hosts that load
	 * after Agents Manager can check this flag synchronously instead of
	 * waiting for the event.
	 */
	isReady?: boolean;
}

/**
 * Data injected by agents-manager inline script.
 */
interface AgentsManagerData {
	useUnifiedExperience?: boolean;
	agentId?: string;
}

/**
 * Extend Window interface for cross-bundle data sharing.
 */
interface Window {
	__agentsManagerActions?: AgentsManagerActions;
	agentsManagerData?: AgentsManagerData;
}
