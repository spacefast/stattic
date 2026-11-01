// Shared by the local native runner checks and the retained-site contract.
export const cronReportSource = `
const headers = globalThis.__statticZeroRequest.headers;
globalThis.__statticZeroResult = JSON.stringify({
  status: 200,
  body: JSON.stringify({ cron: headers['x-spacefast-cron'] ?? null, authorization: headers.authorization ?? null }),
});
`;

export const usersProtectedSource = `
const auth = globalThis.__statticAuth.current();
globalThis.__statticZeroResult = JSON.stringify({
  status: auth?.isAuthenticated ? 200 : 401,
  body: JSON.stringify(auth?.isAuthenticated ? { userId: auth.userId } : { error: 'sign_in_required' }),
});
`;

export const cachePolicySource = `
const policy = globalThis.__statticZeroRequest.query.includes('policy=private-no-store') ? 'private-no-store' : 'no-store';
globalThis.__statticZeroResult = JSON.stringify({
  status: 200,
  headers: { 'cache-control': policy === 'private-no-store' ? 'private, no-store' : 'no-store' },
  body: JSON.stringify({ policy, authenticated: globalThis.__statticAuth.current()?.isAuthenticated === true }),
});
`;
