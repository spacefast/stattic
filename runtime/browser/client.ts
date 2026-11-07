// Shared host exports. App contracts are composed from the selected version by PHP.
export {
  getIdentity,
  getAuthValue,
  canAccessApp,
  signInWithGoogle,
  signInWithGravatar,
  signInWithSpacefast,
  signOut,
  useConnectorState,
  readConnectorRoleState,
  retryConnectorQueries,
  storage,
} from "./legacy-zero.js";
export {
  createCollab,
  canonicalPagePath,
  commentRoomKey,
  fetchCollabManifest,
} from "../../packages/collab-sdk/src/index.js";
