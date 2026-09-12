const finiteCoordinate = (value, min, max, name) => {
  const number = Number(value);
  if (!Number.isFinite(number) || number < min || number > max) {
    throw new RangeError(`invalid_${name}`);
  }
  return number;
};

const coordinates = (lat, lng) => {
  const latitude = finiteCoordinate(lat, -90, 90, 'latitude');
  const longitude = finiteCoordinate(lng, -180, 180, 'longitude');
  return { latitude, longitude };
};

export const buildGoogleMapsUrl = (lat, lng, label = '') => {
  const { latitude, longitude } = coordinates(lat, lng);
  const params = new URLSearchParams({
    api: '1',
    destination: `${latitude},${longitude}`,
    dir_action: 'navigate',
  });
  const safeLabel = String(label || '').trim();
  return `https://www.google.com/maps/dir/?${params.toString()}${safeLabel ? `#${encodeURIComponent(safeLabel)}` : ''}`;
};

export const buildWazeUrl = (lat, lng) => {
  const { latitude, longitude } = coordinates(lat, lng);
  const params = new URLSearchParams({
    ll: `${latitude},${longitude}`,
    navigate: 'yes',
  });
  return `https://waze.com/ul?${params.toString()}`;
};
