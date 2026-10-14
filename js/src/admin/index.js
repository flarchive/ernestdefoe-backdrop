import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import Button from 'flarum/common/components/Button';
import classList from 'flarum/common/utils/classList';

import CoverModal from '../common/components/CoverModal';
import Gallery from './components/Gallery';
import { coverStyle, frameOf, layer } from '../common/frame';

const t = (key) => app.translator.trans(`ernestdefoe-backdrop.admin.${key}`);
const key = (name) => `ernestdefoe-backdrop.${name}`;

export const extend = [
  new Extend.Admin()
    .setting(() => ({ setting: key('height'), type: 'number', min: 96, max: 600, label: t('height') }))
    .setting(() => ({ setting: key('height_mobile'), type: 'number', min: 96, max: 600, label: t('height_mobile') }))
    .setting(() => ({
      setting: key('overlay'),
      type: 'select',
      options: { gradient: t('overlay_gradient'), darken: t('overlay_darken'), none: t('overlay_none') },
      default: 'gradient',
      label: t('overlay'),
      help: t('overlay_help'),
    }))
    .setting(() => ({ setting: key('on_cards'), type: 'boolean', label: t('on_cards') }))
    .setting(() => ({ setting: key('on_directory'), type: 'boolean', label: t('on_directory'), help: t('on_directory_help') }))
    .setting(() => ({ setting: key('use_fof_upload'), type: 'boolean', label: t('use_fof_upload'), help: t('use_fof_upload_help') }))
    .setting(() => ({ setting: key('max_size'), type: 'number', min: 64, max: 20480, label: t('max_size'), help: t('max_size_help') }))
    .customSetting(() => <DefaultCover />)
    .customSetting(() => <Gallery />)
    .permission(() => ({ icon: 'fas fa-images', label: t('permission_choose'), permission: key('choose') }), 'start')
    .permission(() => ({ icon: 'fas fa-upload', label: t('permission_upload'), permission: key('set') }), 'start'),
];

// The default cover is saved by its own endpoint as soon as it is changed, not
// by the settings page's Save button: it is a file, not a value.
function DefaultCover() {
  return {
    view() {
      const config = app.forum.attribute('backdrop') || {};
      const cover = config.default;

      return (
        <div className="Form-group BackdropDefault">
          <label>{t('default')}</label>
          <div className="helpText">{t('default_help')}</div>
          {cover && (
            <div
              className={classList('BackdropDefault-preview', `Backdrop--${config.overlay || 'gradient'}`, cover.zoom < 1 && 'Backdrop--out')}
              style={coverStyle(cover.url, frameOf(cover))}
            >
              {layer()}
            </div>
          )}
          <Button className="Button" icon="fas fa-panorama" onclick={() => open(cover)}>
            {cover ? t('default_change') : t('default_set')}
          </Button>
        </div>
      );
    },
  };
}

function open(cover) {
  const url = `${app.forum.attribute('apiUrl')}/backdrop/default`;
  const send = (method, body) =>
    app.request({ method, url, body, serialize: body instanceof FormData ? (raw) => raw : undefined }).then((response) => {
      app.forum.data.attributes.backdrop = { ...app.forum.attribute('backdrop'), default: response.data };
      m.redraw();
    });

  app.modal.show(CoverModal, {
    title: t('default'),
    cover,
    upload: (body) => send('POST', body),
    reframe: (frame) => send('PATCH', frame),
    remove: () => send('DELETE'),
  });
}
