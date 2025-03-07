jQuery(document).ready(function ($) {
    let lastVideosState = []; // Store the last known state of videos

    // Handle form submission
    $('#vodpress-submit-form').on('submit', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $status = $('#vodpress-submit-status');

        $status.html('<p>Loading...</p>');

        $.ajax({
            url: vodpress.ajaxUrl,
            type: 'POST',
            data: {
                action: 'vodpress_submit_video',
                nonce: vodpress.nonce,
                video_url: $form.find('#video_url').val()
            },
            success: function (response) {
                $status.html(
                    response.success
                        ? '<div class="notice notice-success"><p>' + vodpress.i18n.submitSuccess + '</p></div>'
                        : '<div class="notice notice-error"><p>' + (response.data.message || vodpress.i18n.submitError) + '</p></div>'
                );
                if (response.success) {
                    setTimeout(function () { $status.empty(); updateVideosStatus(); }, 2000); // آپدیت جدول بعد از موفقیت
                }
            },
            error: function () {
                $status.html('<div class="notice notice-error"><p>' + vodpress.i18n.submitError + '</p></div>');
            }
        });
    });

    // Automatically update video statuses
    function updateVideosStatus() {
        $.ajax({
            url: vodpress.ajaxUrl,
            type: 'POST',
            data: {
                action: 'vodpress_get_videos_status',
                nonce: vodpress.nonce
            },
            success: function (response) {
                if (response.success && JSON.stringify(response.data) !== JSON.stringify(lastVideosState)) {
                    updateVideosTable(response.data);
                    lastVideosState = response.data;
                }
            },
            error: function () {
                console.log('Failed to fetch video status');
            }
        });
    }

    // Update the videos table
    function updateVideosTable(videos) {
        var $tbody = $('.vodpress-videos-section tbody');
        $tbody.empty();

        if (!videos || videos.length === 0) {
            $tbody.append('<tr><td colspan="7">No videos found.</td></tr>');
            return;
        }

        videos.forEach(function (video) {
            var actions = '';
            if (video.status === 'failed' || video.status === 'pending') {
                actions = '<button class="button button-small vodpress-retry" data-video-id="' + video.id + '">Retry</button>';
            } else if (video.status === 'completed' && video.conversion_url) {
                actions = '<button class="button button-small vodpress-copy-url" data-url="' + video.conversion_url + '">Copy URL</button>';
            }

            var errorMessage = video.error_message ? '<br><small style="color: #dc3232;">' + video.error_message + '</small>' : '';

            $tbody.append(
                '<tr>' +
                '<td>' + video.id + '</td>' +
                '<td><a href="' + video.video_url + '" target="_blank">' + video.video_url.substring(0, 50) + (video.video_url.length > 50 ? '...' : '') + '</a></td>' +
                '<td><span class="vodpress-status-' + video.status + '">' + video.status_label + '</span>' + errorMessage + '</td>' +
                '<td>' + video.created_at + '</td>' +
                '<td>' + video.updated_at + '</td>' +
                '<td>' + (video.conversion_url ? '<a href="' + video.conversion_url + '" target="_blank">View</a>' : '-') + '</td>' +
                '<td>' + actions + '</td>' +
                '</tr>'
            );
        });

        bindTableActions();
    }

    // Bind actions to table buttons
    function bindTableActions() {
        $('.vodpress-copy-url').off('click').on('click', function () {
            var url = $(this).data('url');
            navigator.clipboard.writeText(url).then(function () {
                alert('URL copied to clipboard!');
            });
        });

        $('.vodpress-retry').off('click').on('click', function () {
            var videoId = $(this).data('video-id');
            var $button = $(this);
            $button.prop('disabled', true).text('Retrying...');

            $.ajax({
                url: vodpress.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'vodpress_retry_video',
                    nonce: vodpress.nonce,
                    video_id: videoId
                },
                success: function (response) {
                    if (response.success) {
                        $('#vodpress-submit-status').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                        setTimeout(function () { $('#vodpress-submit-status').empty(); updateVideosStatus(); }, 2000);
                    } else {
                        $('#vodpress-submit-status').html('<div class="notice notice-error"><p>' + (response.data.message || 'Unknown error') + '</p></div>');
                        $button.prop('disabled', false).text('Retry');
                    }
                },
                error: function () {
                    $('#vodpress-submit-status').html('<div class="notice notice-error"><p>Failed to retry video</p></div>');
                    $button.prop('disabled', false).text('Retry');
                }
            });
        });
    }

    // Check for updates every 5 seconds
    setInterval(updateVideosStatus, 5000);

    // Initial update on page load
    updateVideosStatus();
});