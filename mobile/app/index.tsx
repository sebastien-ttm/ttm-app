import { Redirect } from 'expo-router';

// Root: AuthGate in _layout will route us properly. This is just a fallback.
export default function Index() {
  return <Redirect href="/(tabs)" />;
}
