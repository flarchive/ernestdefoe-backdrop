import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import UserCard from 'flarum/forum/components/UserCard';
import UserControls from 'flarum/forum/utils/UserControls';
import Button from 'flarum/common/components/Button';
import classList from 'flarum/common/utils/classList';

import CoverModal from '../common/components/CoverModal';
import { coverStyle, frameOf, layer } from '../common/frame';

/*
 * Two extension points, both plain `extend`s on core's own methods:
 * UserCard's view (hero, hover card, member directory) and the user controls
 * menu. Nothing is overridden, nothing is patched on `app`, and nothing is
 * added to <body>, so other extensions' hooks run exactly as they would
 * without this one.
 */
app.initializers.add('ernestdefoe-backdrop', () => {
  extend(UserCard.prototype, 'view', function (vnode) {
    const user = this.attrs.user;
    const config = app.forum.attribute('backdrop');

    if (!user || !config || !vnode?.attrs) return;

    const context = this.attrs.className || '';
    const card = context.includes('UserCard--popover');
    const directory = context.includes('UserCard--directory');

    if ((card && !config.onCards) || (directory && !config.onDirectory)) return;

    const own = user.attribute('backdrop');
    const cover = own || config.default;

    if (!cover) return;

    const frame = frameOf(cover);
    const vars = {
      ...coverStyle(card || directory ? cover.small : cover.url, frame),
      '--backdrop-height': `${config.height}px`,
      '--backdrop-height-mobile': `${config.heightMobile}px`,
    };
    const style = vnode.attrs.style;

    vnode.attrs.style =
      typeof style === 'string'
        ? `${style};${Object.entries(vars)
            .map(([k, v]) => `${k}:${v}`)
            .join(';')}`
        : { ...(style || {}), ...vars };
    vnode.attrs.className = classList(
      vnode.attrs.className,
      'Backdrop',
      `Backdrop--${config.overlay}`,
      frame.zoom < 1 && 'Backdrop--out',
      !own && 'Backdrop--default'
    );
    vnode.children = [layer(), ...(Array.isArray(vnode.children) ? vnode.children : [vnode.children])];
  });

  extend(UserControls, 'userControls', (items, user) => {
    const canSet = user.attribute('canUploadBackdrop') || user.attribute('canChooseBackdrop');

    // Without either permission you can still frame or remove a cover you have.
    if (!canSet && !(user.attribute('backdrop') && user === app.session.user)) return;

    items.add(
      'backdrop',
      <Button icon="fas fa-panorama" onclick={() => openEditor(user)}>
        {app.translator.trans('ernestdefoe-backdrop.forum.edit_cover')}
      </Button>
    );
  });
});

function openEditor(user) {
  const url = `${app.forum.attribute('apiUrl')}/users/${user.id()}/backdrop`;
  const send = (method, body) =>
    app
      .request({ method, url, body, serialize: body instanceof FormData ? (raw) => raw : undefined })
      .then((payload) => app.store.pushPayload(payload));

  app.modal.show(CoverModal, {
    title: app.translator.trans('ernestdefoe-backdrop.forum.edit_cover'),
    cover: user.attribute('backdrop'),
    canUpload: !!user.attribute('canUploadBackdrop'),
    loadPresets: user.attribute('canChooseBackdrop')
      ? () => app.request({ method: 'GET', url: `${app.forum.attribute('apiUrl')}/backdrop/presets` }).then((r) => r.data)
      : undefined,
    upload: (body) => send('POST', body),
    choose: (body) => app.request({ method: 'POST', url: `${url}/preset`, body }).then((payload) => app.store.pushPayload(payload)),
    reframe: (frame) => send('PATCH', frame),
    remove: () => send('DELETE'),
  });
}
