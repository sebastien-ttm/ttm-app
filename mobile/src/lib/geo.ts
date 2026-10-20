/**
 * Localisation de l'appareil (API de géolocalisation du navigateur ; sur mobile natif, celle de React Native) et liens
 * de carte. Utilisée par les groupes d'entraînement : point GPS joint à un commentaire et position partagée en direct.
 */
export type GeoPoint = { latitude: number; longitude: number; accuracy: number | null };

type GeoProvider = {
  getCurrentPosition: (ok: (p: GeolocationPosition) => void, ko: (e: GeolocationPositionError) => void, o?: PositionOptions) => void;
  watchPosition: (ok: (p: GeolocationPosition) => void, ko: (e: GeolocationPositionError) => void, o?: PositionOptions) => number;
  clearWatch: (id: number) => void;
};

function provider(): GeoProvider | null {
  if (typeof navigator === 'undefined' || !('geolocation' in navigator) || !navigator.geolocation) return null;
  return navigator.geolocation as unknown as GeoProvider;
}

export function geolocationAvailable(): boolean {
  return provider() !== null;
}

function toPoint(p: GeolocationPosition): GeoPoint {
  return {
    latitude: p.coords.latitude,
    longitude: p.coords.longitude,
    accuracy: typeof p.coords.accuracy === 'number' && Number.isFinite(p.coords.accuracy) ? p.coords.accuracy : null,
  };
}

function errorMessage(e: GeolocationPositionError): string {
  switch (e.code) {
    case 1: return 'Localisation refusée : autorisez-la pour cette appli dans les réglages de votre appareil ou de votre navigateur.';
    case 2: return 'Position introuvable : vérifiez que le GPS est activé et réessayez.';
    case 3: return 'La position met trop de temps à arriver : réessayez à l\'extérieur ou avec le GPS activé.';
    default: return 'Localisation impossible.';
  }
}

const OPTIONS: PositionOptions = { enableHighAccuracy: true, timeout: 20_000, maximumAge: 5_000 };

/** Position actuelle, une fois. Rejette avec un message en français si elle est refusée ou indisponible. */
export function getCurrentPoint(): Promise<GeoPoint> {
  return new Promise((resolve, reject) => {
    const geo = provider();
    if (!geo) {
      reject(new Error('La localisation n\'est pas disponible sur cet appareil.'));
      return;
    }
    geo.getCurrentPosition((p) => resolve(toPoint(p)), (e) => reject(new Error(errorMessage(e))), OPTIONS);
  });
}

/**
 * Suit la position de l'appareil : `onPoint` est appelé à chaque nouvelle position. Renvoie la fonction qui arrête le
 * suivi. `onError` reçoit un message en français (refus de la localisation, GPS indisponible…).
 */
export function watchPoint(onPoint: (point: GeoPoint) => void, onError: (message: string) => void): () => void {
  const geo = provider();
  if (!geo) {
    onError('La localisation n\'est pas disponible sur cet appareil.');
    return () => undefined;
  }
  const id = geo.watchPosition((p) => onPoint(toPoint(p)), (e) => onError(errorMessage(e)), OPTIONS);
  return () => geo.clearWatch(id);
}

/** Lien OpenStreetMap vers un point (ouvre la carte, ou l'appli de cartes du téléphone). */
export function mapUrl(latitude: number, longitude: number): string {
  const lat = latitude.toFixed(6);
  const lon = longitude.toFixed(6);
  return `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lon}#map=17/${lat}/${lon}`;
}

/** URL de la carte OpenStreetMap intégrable (iframe, web) centrée sur un point, avec son repère. */
export function embedMapUrl(latitude: number, longitude: number): string {
  const lat = latitude.toFixed(6);
  const lon = longitude.toFixed(6);
  const d = 0.004;
  const bbox = [longitude - d, latitude - d, longitude + d, latitude + d].map((n) => n.toFixed(6)).join('%2C');
  return `https://www.openstreetmap.org/export/embed.html?bbox=${bbox}&layer=mapnik&marker=${lat}%2C${lon}`;
}

/** « 43.60443, 1.44356 » : coordonnées lisibles (copie / saisie dans une autre appli). */
export function formatPoint(latitude: number, longitude: number): string {
  return `${latitude.toFixed(5)}, ${longitude.toFixed(5)}`;
}
