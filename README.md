# apForms Community

apForms is a free WordPress form builder for collecting entries and sending notifications. Forms and entries are stored on your site. The separately available [apForms Pro](https://artistprowp.com/apforms/) add-on offers conditional logic, file uploads and optional AI features, with paid updates and support.

## Install

1. Download the community ZIP from [Releases](https://github.com/mikelawson68/apforms-community/releases), upload it under Plugins > Add New, and activate it.
2. Create a form in apForms and add its shortcode to a page.
3. Configure notifications and test the complete form before sharing it.

Requires WordPress 6.4 or later and PHP 8.0 or later. This community download has no built-in updater; check this repository for releases and back up your site before updating. WordPress.org review is not yet complete.

## Permissions and privacy

Form entries can contain personal data. Limit management access and set your own retention and backup policy. If your site embeds the optional frontend management template, it requires the WordPress `manage_options` permission; subscriber and editor accounts cannot view its management screens by default. The built-in administrator AJAX form and entry reads also require an `apforms_admin` nonce.

## Paid features and support

See [apForms and apForms Pro](https://artistprowp.com/apforms/) for paid add-ons, updates and support. The free core works without a purchase. See [readme.txt](readme.txt) for release information.

## License

GPL version 2 or later; see [LICENSE.txt](LICENSE.txt). The bundled SortableJS library retains its [MIT license](assets/vendor/sortablejs/LICENSE).
