export const coordinatesFromPosition = (position) => {
  const latitude = Number(position?.coords?.latitude);
  const longitude = Number(position?.coords?.longitude);
  if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
    throw new RangeError('invalid_geolocation_position');
  }
  return [longitude, latitude];
};

export const startGeolocation = ({ navigatorObject = globalThis.navigator, onPosition = () => {}, onError = () => {} } = {}) => {
  const geolocation = navigatorObject?.geolocation;
  if (!geolocation || typeof geolocation.watchPosition !== 'function') {
    throw new Error('geolocation_unavailable');
  }
  return geolocation.watchPosition(onPosition, onError, {
    enableHighAccuracy: true,
    maximumAge: 15000,
    timeout: 15000,
  });
};
