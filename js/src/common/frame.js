export const ZOOM_MIN = 0.5;
export const ZOOM_MAX = 4;

export const clamp = (value, min, max, fallback) => {
  const n = Number(value);

  return Number.isFinite(n) ? Math.min(max, Math.max(min, n)) : fallback;
};

export const frameOf = (cover) => ({
  x: clamp(cover?.x, 0, 100, 50),
  y: clamp(cover?.y, 0, 100, 50),
  zoom: clamp(cover?.zoom, ZOOM_MIN, ZOOM_MAX, 1),
});

// A URL is only ever placed inside url("…"), with the two characters that
// could end that string percent-encoded.
export const cssUrl = (url) => `url("${String(url).replace(/["\\\n\r]/g, (c) => encodeURIComponent(c))}")`;

/**
 * The custom properties one cover is painted from. They sit on the card itself
 * so a theme can use them too.
 */
export const coverStyle = (url, frame) => ({
  '--backdrop-image': cssUrl(url),
  '--backdrop-x': `${frame.x}%`,
  '--backdrop-y': `${frame.y}%`,
  '--backdrop-zoom': String(frame.zoom),
});

/**
 * The painted layer: the image (and, zoomed out, a blurred fill behind it),
 * then the readability shade. Shared by the cards and the editor, so the
 * editor shows exactly what will be rendered.
 */
export const layer = () => (
  <div className="Backdrop-layer" aria-hidden="true">
    <div className="Backdrop-shade" />
  </div>
);
