const coordinatePair = (pair) => {
  if (!Array.isArray(pair) || pair.length < 2) {
    throw new Error('invalid_route_coordinate');
  }

  const longitude = Number(pair[0]);
  const latitude = Number(pair[1]);

  if (
    !Number.isFinite(longitude) ||
    !Number.isFinite(latitude) ||
    longitude < -180 ||
    longitude > 180 ||
    latitude < -90 ||
    latitude > 90
  ) {
    throw new Error('invalid_route_coordinate');
  }

  return [longitude, latitude];
};

export const reorderStopUuids = (uuids, fromIndex, toIndex) => {
  if (!Array.isArray(uuids)) {
    throw new Error('stop_list_required');
  }

  if (
    !Number.isInteger(fromIndex) ||
    !Number.isInteger(toIndex) ||
    fromIndex < 0 ||
    toIndex < 0 ||
    fromIndex >= uuids.length ||
    toIndex >= uuids.length
  ) {
    throw new Error('stop_index_out_of_range');
  }

  const reordered = [...uuids];
  const [moved] = reordered.splice(fromIndex, 1);
  reordered.splice(toIndex, 0, moved);
  return reordered;
};

export const mapPoiCategoryVisibility = (pois, categoryVisibility = {}) => {
  if (!Array.isArray(pois)) {
    return [];
  }

  return pois.map((poi) => {
    const categoryId = poi?.category_id;
    const key = categoryId === null || categoryId === undefined ? null : String(categoryId);
    const visible = key !== null && Object.prototype.hasOwnProperty.call(categoryVisibility, key)
      ? Boolean(categoryVisibility[key])
      : true;

    return { ...poi, visible };
  });
};

export const lineCoordinatesToGeoJson = (coordinates) => {
  if (!Array.isArray(coordinates) || coordinates.length < 2) {
    throw new Error('route_line_requires_two_points');
  }

  return {
    type: 'LineString',
    coordinates: coordinates.map(coordinatePair),
  };
};
