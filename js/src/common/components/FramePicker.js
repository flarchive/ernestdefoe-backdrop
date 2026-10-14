import app from 'flarum/common/app';
import Component from 'flarum/common/Component';
import classList from 'flarum/common/utils/classList';

import { clamp, coverStyle, layer, ZOOM_MAX, ZOOM_MIN } from '../frame';

/**
 * Drag the image to frame it, scroll or use the slider to zoom, or use the
 * arrow keys and + / -. Pans in proportion to how far the image overhangs the
 * box, so the point under the pointer stays under the pointer.
 *
 * Attrs: url, frame {x, y, zoom}, overlay, onchange(frame)
 */
export default class FramePicker extends Component {
  oninit(vnode) {
    super.oninit(vnode);

    this.natural = null;
    this.drag = null;
    this.move = this.move.bind(this);
    this.end = this.end.bind(this);
  }

  view() {
    const { url, frame, overlay } = this.attrs;

    if (url !== this.loadedUrl) this.measure(url);

    return (
      <div className="BackdropPicker">
        <div
          className={classList('BackdropPicker-stage', `Backdrop--${overlay}`, frame.zoom < 1 && 'Backdrop--out', this.drag && 'is-dragging')}
          style={coverStyle(url, frame)}
          tabindex="0"
          role="application"
          aria-label={app.translator.trans('ernestdefoe-backdrop.lib.editor.stage_label')}
          onpointerdown={this.start.bind(this)}
          onwheel={this.wheel.bind(this)}
          onkeydown={this.key.bind(this)}
        >
          {layer()}
        </div>
        <label className="BackdropPicker-zoom">
          <span>{app.translator.trans('ernestdefoe-backdrop.lib.editor.zoom')}</span>
          <input type="range" min={ZOOM_MIN} max={ZOOM_MAX} step="0.05" value={frame.zoom} oninput={(e) => this.change({ zoom: e.target.value })} />
          <output>{frame.zoom.toFixed(2)}×</output>
        </label>
      </div>
    );
  }

  onremove(vnode) {
    super.onremove(vnode);
    this.unbind();
  }

  measure(url) {
    this.loadedUrl = url;
    this.natural = null;

    const img = new Image();
    img.onload = () => {
      if (this.loadedUrl === url) this.natural = { w: img.naturalWidth, h: img.naturalHeight };
    };
    img.src = url;
  }

  change(partial) {
    const next = { ...this.attrs.frame, ...partial };

    this.attrs.onchange({
      x: Math.round(clamp(next.x, 0, 100, 50) * 100) / 100,
      y: Math.round(clamp(next.y, 0, 100, 50) * 100) / 100,
      zoom: Math.round(clamp(next.zoom, ZOOM_MIN, ZOOM_MAX, 1) * 100) / 100,
    });
  }

  // How many pixels the rendered image overhangs the box on each axis.
  overhang() {
    const box = this.element.querySelector('.BackdropPicker-stage').getBoundingClientRect();

    if (!this.natural) return { x: 0, y: 0 };

    const scale = Math.max(box.width / this.natural.w, box.height / this.natural.h) * this.attrs.frame.zoom;

    return { x: this.natural.w * scale - box.width, y: this.natural.h * scale - box.height };
  }

  start(e) {
    if (e.button) return;

    e.preventDefault();
    e.currentTarget.focus();

    this.drag = { px: e.clientX, py: e.clientY, x: this.attrs.frame.x, y: this.attrs.frame.y, range: this.overhang() };

    window.addEventListener('pointermove', this.move);
    window.addEventListener('pointerup', this.end);
    window.addEventListener('pointercancel', this.end);
  }

  move(e) {
    const d = this.drag;

    if (!d) return;

    this.change({
      x: Math.abs(d.range.x) > 1 ? d.x - ((e.clientX - d.px) / d.range.x) * 100 : d.x,
      y: Math.abs(d.range.y) > 1 ? d.y - ((e.clientY - d.py) / d.range.y) * 100 : d.y,
    });
    m.redraw();
  }

  end() {
    this.drag = null;
    this.unbind();
    m.redraw();
  }

  unbind() {
    window.removeEventListener('pointermove', this.move);
    window.removeEventListener('pointerup', this.end);
    window.removeEventListener('pointercancel', this.end);
  }

  wheel(e) {
    e.preventDefault();
    this.change({ zoom: this.attrs.frame.zoom + (e.deltaY < 0 ? 0.1 : -0.1) });
  }

  key(e) {
    const step = e.shiftKey ? 10 : 2;
    const { x, y, zoom } = this.attrs.frame;
    const moves = {
      ArrowLeft: { x: x - step },
      ArrowRight: { x: x + step },
      ArrowUp: { y: y - step },
      ArrowDown: { y: y + step },
      '+': { zoom: zoom + 0.1 },
      '=': { zoom: zoom + 0.1 },
      '-': { zoom: zoom - 0.1 },
    };

    if (!moves[e.key]) return;

    e.preventDefault();
    this.change(moves[e.key]);
  }
}
