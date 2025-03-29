jQuery(document).ready(function ($) {
    let lastVideosState = []; // Store the last known state of videos
    let searchTerm = ''; // Store the current search term

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
                video_url: $form.find('#video_url').val(),
                video_title: $form.find('#video_title').val()
            },
            success: function (response) {
                $status.html(
                    response.success
                        ? '<div class="notice notice-success"><p>' + vodpress.i18n.submitSuccess + '</p></div>'
                        : '<div class="notice notice-error"><p>' + (response.data.message || vodpress.i18n.submitError) + '</p></div>'
                );
                if (response.success) {
                    $form.find('#video_url').val('');
                    $form.find('#video_title').val('');
                    setTimeout(function () { $status.empty(); updateVideosStatus(); }, 2000);
                }
            },
            error: function () {
                $status.html('<div class="notice notice-error"><p>' + vodpress.i18n.submitError + '</p></div>');
            }
        });
    });

    // Handle search
    $('#vodpress-search-button').on('click', function() {
        searchTerm = $('#vodpress-search').val();
        updateVideosStatus();
    });
    
    // Handle search clear
    $('#vodpress-clear-search').on('click', function() {
        $('#vodpress-search').val('');
        searchTerm = '';
        updateVideosStatus();
    });
    
    // Handle pressing Enter in search field
    $('#vodpress-search').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            searchTerm = $(this).val();
            updateVideosStatus();
        }
    });

    // Automatically update video statuses
    function updateVideosStatus() {
        $.ajax({
            url: vodpress.ajaxUrl,
            type: 'POST',
            data: {
                action: 'vodpress_get_videos_status',
                nonce: vodpress.nonce,
                search: searchTerm
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
            $tbody.append('<tr><td colspan="8">No videos found.</td></tr>');
            return;
        }

        videos.forEach(function (video) {
            var actions = '';
            if (video.status === 'failed') {
                actions = '<button class="button button-small vodpress-retry" data-video-id="' + video.id + '">Retry</button> ';
                actions += '<button class="button button-small vodpress-delete" data-video-id="' + video.id + '">Delete</button>';
            } else if (video.status === 'completed' && video.conversion_url) {
                actions = '<button class="button button-small vodpress-copy-url" data-url="' + video.conversion_url + '">Copy URL</button> ';
            } else if (video.status === 'pending') {
                actions = '<button class="button button-small vodpress-retry" data-video-id="' + video.id + '">Retry</button> ';
                actions += '<button class="button button-small vodpress-delete" data-video-id="' + video.id + '">Delete</button>';
            } else {
                actions = ''; 
            }

            var errorMessage = video.error_message ? '<br><small style="color: #dc3232;">' + video.error_message + '</small>' : '';

            $tbody.append(
                '<tr>' +
                '<td>' + video.id + '</td>' +
                '<td>' + video.title + '</td>' +
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

        $('.vodpress-delete').off('click').on('click', function() {
            if (confirm('Are you sure you want to delete this video?')) {
                var videoId = $(this).data('video-id');
                var $button = $(this);
                $button.prop('disabled', true).text('Deleting...');
                
                $.ajax({
                    url: vodpress.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'vodpress_delete_video',
                        nonce: vodpress.nonce,
                        video_id: videoId
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#vodpress-submit-status').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                            setTimeout(function() { $('#vodpress-submit-status').empty(); updateVideosStatus(); }, 2000);
                        } else {
                            $('#vodpress-submit-status').html('<div class="notice notice-error"><p>' + (response.data.message || 'Unknown error') + '</p></div>');
                            $button.prop('disabled', false).text('Delete');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Ajax error:', status, error);
                        $('#vodpress-submit-status').html('<div class="notice notice-error"><p>Failed to delete video: ' + error + '</p></div>');
                        $button.prop('disabled', false).text('Delete');
                    }
                });
            }
        });
    }

    // Check for updates every 5 seconds
    setInterval(updateVideosStatus, 5000);

    // Initial update on page load
    updateVideosStatus();
});