# Backdrop

[![MIT license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![Latest Stable Version](https://img.shields.io/packagist/v/ernestdefoe/backdrop.svg)](https://packagist.org/packages/ernestdefoe/backdrop)

Profile covers for [Flarum 2](https://flarum.org). Members pick a cover from a gallery you curate, or upload their own, then drag it to frame it and zoom. It shows on their profile, on hover cards and in the member directory.

Backdrop is built to sit quietly beside your other extensions: it adds to two of core's own methods and nothing else. It does not patch the app, override core components, add classes to `<body>`, restyle other extensions, or add queries to anyone else's pages.

![A profile with its cover](.github/screenshots/profile.png)

## Features

- **A cover gallery.** Upload up to 24 covers at once in the admin, frame each one, and drag them into the order members see. Members choose one and can still frame it their own way. Each gallery image is stored once, however many members use it. Delete one and its members fall back to the default cover.
- **Upload and frame.** Drag the image to choose what shows, zoom from 0.5× to 4× with the slider, the scroll wheel or `+` / `-`, and move it with the arrow keys. Below 1× the edges are filled with a blurred copy of the image. Reframe at any time without uploading again.
- **Everywhere a profile card appears:** the profile, hover cards on posts, and [FoF User Directory](https://github.com/FriendsOfFlarum/user-directory). Small cards get a lighter copy of the image.
- **A default cover** for members who have not set one, chosen and framed in the admin.
- **A readability shade** (gradient, even darken or none) so names and stats stay legible on bright photos.
- **Light on your server.** Uploads are stored as WebP, at most 1920px wide and 4096px tall, plus a 960px copy for cards. Animated GIFs stay animated, up to 8 million pixels across all their frames. JPEG, PNG, WebP and GIF are accepted, and size, dimensions and frame count are all checked before an image is decoded.
- **Works with FoF Upload, or without it.** When [FoF Upload](https://github.com/FriendsOfFlarum/upload) is enabled, a member's cover goes through it like their other images: its storage (local, S3 and the rest), resizing, watermark and WebP thumbnails, and the cover appears in their My Media. Deleting it there removes the cover. Forums without FoF Upload, and members it does not let upload images, use Backdrop's own storage (`public/assets/backdrop`). An admin setting turns the FoF Upload route off. Gallery and default covers are site assets and always use Backdrop's storage.
- **Permissions, in Flarum's own grid.** *Choose a profile cover from the gallery* and *Upload own profile cover* are separate, and both go to Members on install. Grant them to any groups you like: revoke uploading for a gallery-only forum, or keep uploads for a supporters group. A member who loses both can still frame or remove the cover they have. Changing someone else's cover needs core's *Edit users* permission. Uploads are limited to six a minute per member.

![Choosing and framing a cover from the gallery](.github/screenshots/editor.png)

![A hover card showing the forum's default cover](.github/screenshots/hover-card.png)

![The settings page, with the default cover and the gallery](.github/screenshots/admin.png)

## Installation

```sh
composer require ernestdefoe/backdrop
php flarum migrate
php flarum cache:clear
```

Then enable **Backdrop** in the admin panel.

## Updating

```sh
composer update ernestdefoe/backdrop
php flarum migrate
php flarum cache:clear
```

## Switching from Cover Studio or Profile Cover

Backdrop brings across covers from **tryhackx/flarum-cover-studio** and **sycho/flarum-profile-cover**, with their framing, and from Cover Studio also its settings, its default cover and the groups allowed to set covers.

```sh
# 1. Disable the old extension in the admin, then remove it
composer remove tryhackx/flarum-cover-studio      # or sycho/flarum-profile-cover

# 2. Install Backdrop and enable it in the admin
composer require ernestdefoe/backdrop
php flarum migrate
php flarum cache:clear

# 3. Copy the cover files onto Backdrop's own storage
php flarum backdrop:import
```

Enabling Backdrop brings every cover across straight away, still served from where it was. Cover Studio's covers are FoF Upload files, so while FoF Upload stays enabled they stay there, in each member's My Media, with their WebP thumbnails. Step 3 copies everything else that is on your server (sycho's covers, or Cover Studio's if you remove FoF Upload) onto Backdrop's storage. It is safe to run more than once.

Nothing is deleted: the old extension's tables, columns and files are left exactly as they were, and a member who already has a Backdrop cover is never overwritten. Covers held on remote storage (S3 and the like) keep working from their URL.

Remove the old extension before installing Backdrop. Composer will refuse to install both, since both would paint the same cards.

## Why it doesn't conflict

| | |
|---|---|
| Patches `app.mount` on the app itself | **No.** A patch on the app instance hides every extension that hooks the app's class, and Flarum boots extensions in a fixed order, so it breaks them every time. Modern Footer's footer, for one, never appears. |
| Overrides core components (`Avatar`, `AvatarEditor`) | **No.** Backdrop only adds to `UserCard`'s view and the user controls menu, and calls through to core. |
| Classes on `<body>` | **No.** Everything is scoped to `.UserCard.Backdrop`, which only cards with a cover get. |
| Restyles other extensions | **No.** |
| Adds a global query scope to `User` | **No.** Cover data is four columns on `users`, like core's own avatar, so it arrives with the row every page already loads. |
| Requires FoF Upload | **No.** It uses FoF Upload when it is there, and its own storage when it is not. |

## For theme authors

A card with a cover carries the classes `Backdrop`, `Backdrop--gradient|darken|none`, `Backdrop--out` (zoomed out) and `Backdrop--default` (the forum's default cover), and these custom properties:

| Property | Value |
|---|---|
| `--backdrop-image` | `url("…")` |
| `--backdrop-x`, `--backdrop-y` | focal point, e.g. `70%` |
| `--backdrop-zoom` | e.g. `1.5` |
| `--backdrop-height`, `--backdrop-height-mobile` | the admin's hero heights |

The image is painted by a `.Backdrop-layer` element placed first inside the card. A theme that draws its own cover can hide that layer and use the properties instead.

## Links

- [Packagist](https://packagist.org/packages/ernestdefoe/backdrop)
- [Source and issues](https://github.com/ernestdefoe/backdrop)

☕ If you like the things I have built, consider [buying me a coffee](https://buymeacoffee.com/ernestdefoe).
