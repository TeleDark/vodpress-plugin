# VODPress - Video on Demand WordPress Plugin

VODPress is a WordPress plugin that enables users to submit video URLs for conversion to HLS (HTTP Live Streaming) format and manage them through a user-friendly admin interface. It integrates with a custom video conversion server to process videos, store them on S3-compatible storage, and provide streaming-ready URLs.

## Features
- Submit video URLs for conversion via an intuitive admin panel.
- Real-time status tracking (Pending, Downloading, Converting, Uploading, Completed, Failed).
- Retry failed conversions with a single click.
- Supports HLS streaming with configurable S3 storage.
- Secure API communication with server using API key hashing.
- Multilingual support with text domain integration.

## Prerequisites
- WordPress 5.0 or higher.
- PHP 7.4 or higher.
- A running instance of the [VODPress Conversion Server](#) (see companion repository).
- An S3-compatible storage service (e.g., AWS S3, MinIO, Cloudflare R2).

## Installation
1. **Download the Plugin**
   - Clone this repository or download the ZIP file:
     ```bash
     git clone https://github.com/yourusername/vodpress.git
     ```
   - Alternatively, upload the ZIP file via WordPress admin (`Plugins > Add New > Upload Plugin`).

2. **Install and Activate**
   - If cloned, place the `vodpress` folder in `wp-content/plugins/`.
   - Activate the plugin from the WordPress admin panel (`Plugins > Installed Plugins`).

3. **Configure Settings**
   - Go to `VODPress > Settings` in the WordPress admin menu.
   - Enter your **API Key**, **Server URL**, and optionally a **Custom S3 Domain** (e.g., `https://r2.public.com`).
   - Save the settings.

## Usage
1. Navigate to `VODPress` in the WordPress admin menu.
2. Enter a video URL in the "Submit Video" form and click "Submit Video".
3. Monitor the conversion status in the "Recent Conversions" table.
4. Once completed, copy the HLS URL or retry failed conversions as needed.

## API Integration
- The plugin communicates with a conversion server via the `/api/convert` endpoint.
- It expects a callback at `/wp-json/vodpress/v1/callback` to update video statuses.
- All requests are authenticated using an `X-API-Key-Hash` header.

## Database
- Creates a custom table (`wp_vodpress_videos`) to store video metadata:
  - `id`, `video_url`, `status`, `conversion_url`, `error_message`, `created_at`, `updated_at`.

## Development
- **Hooks**: Uses WordPress actions and filters for extensibility (`wp_ajax`, `rest_api_init`, etc.).
- **Assets**: Includes custom CSS (`assets/css/style.css`) and JavaScript (`assets/js/script.js`) for the admin interface.
- **Localization**: Supports translations via the `vodpress` text domain.

## License
This project is licensed under the GPL v2 or later - see the [LICENSE](LICENSE) file for details.

## Author
- Morteza Mohammadnezhad

## Support
For issues or feature requests, please open an issue on this repository.