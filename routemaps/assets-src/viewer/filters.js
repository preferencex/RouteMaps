export const visiblePois = (pois = [], categoryVisibility = {}) => (
  Array.isArray(pois) ? pois : []
).filter((poi) => {
  const categoryUuid = poi?.category?.uuid;
  if (!categoryUuid) return true;
  if (!Object.prototype.hasOwnProperty.call(categoryVisibility, categoryUuid)) return true;
  return Boolean(categoryVisibility[categoryUuid]);
});
