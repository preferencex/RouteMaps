export const fullscreenSupported = (documentObject = globalThis.document) => Boolean(
  documentObject?.fullscreenEnabled
  && typeof documentObject?.documentElement?.requestFullscreen === 'function',
);

export const requestViewerFullscreen = async (documentObject = globalThis.document) => {
  if (!fullscreenSupported(documentObject)) {
    throw new Error('fullscreen_unavailable');
  }
  await documentObject.documentElement.requestFullscreen();
};
