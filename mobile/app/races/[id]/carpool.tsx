import { Stack, useLocalSearchParams } from 'expo-router';

import { CarpoolBoard } from '@/components/CarpoolBoard';

/** Page covoiturage d'une proposition de course — voir CarpoolBoard. */
export default function RaceCarpoolScreen() {
  const { id: rawId } = useLocalSearchParams<{ id: string }>();
  return (
    <>
      <Stack.Screen options={{ title: 'Covoiturage' }} />
      <CarpoolBoard kind="race" id={Number(rawId)} />
    </>
  );
}
