export const CREATE_CATEGORY_VALUE = '__create__';

const normalizedName = (value) => String(value || '').trim().toLocaleLowerCase();

export const defaultCategorySelection = (sourceName, categories = [], canCreate = false) => {
  const normalizedSource = normalizedName(sourceName);
  const existing = categories.find((category) => normalizedName(category?.name) === normalizedSource && Number(category?.id) > 0);
  if (existing) return String(existing.id);
  return canCreate ? CREATE_CATEGORY_VALUE : '';
};

export const importSummary = (preview = {}) => {
  const geometry = preview?.geometry || {};
  let routeLines = 0;
  if (geometry.type === 'LineString' && Array.isArray(geometry.coordinates) && geometry.coordinates.length) {
    routeLines = 1;
  } else if (geometry.type === 'MultiLineString' && Array.isArray(geometry.coordinates)) {
    routeLines = geometry.coordinates.filter((line) => Array.isArray(line) && line.length).length;
  }

  return {
    routeLines,
    points: Array.isArray(preview?.points) ? preview.points.length : 0,
    categories: Array.isArray(preview?.categories) ? preview.categories.length : 0,
    polygons: Array.isArray(preview?.metadata?.polygons) ? preview.metadata.polygons.length : 0,
    warnings: Array.isArray(preview?.warnings) ? preview.warnings.length : 0,
  };
};

export const humanImportWarnings = (warnings = []) => {
  const styles = [];
  const messages = [];

  warnings.forEach((warning) => {
    const value = String(warning || '');
    if (value.startsWith('unsupported_kml_style:')) {
      const style = value.slice('unsupported_kml_style:'.length).trim();
      if (style && !styles.includes(style)) styles.push(style);
      return;
    }
    if (value === 'route_geometry_built_from_points') {
      messages.push('O ficheiro não contém uma linha de percurso; o RouteMaps construiu o percurso a partir da ordem dos pontos.');
      return;
    }
    if (value) messages.push(value);
  });

  if (styles.length) {
    messages.unshift(`Estilos visuais KML ignorados: ${styles.join(', ')}. O percurso e os pontos não são afetados.`);
  }

  return messages;
};
