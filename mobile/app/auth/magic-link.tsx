// Public alias for the magic link callback URL.
// Native deep links use `ttm://(auth)/magic-link?token=` (group-aware).
// Web URLs from email use `http://app/auth/magic-link?token=` (no group prefix).
// This file makes both shapes resolve to the same screen.
export { default } from '../(auth)/magic-link';
