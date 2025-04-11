jQuery(document).ready(function ($) {
    let lastVideosState = []; // Store the last known state of videos
    let searchTerm = ''; // Store the current search term
    let searchTimeout = null;

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
                if (response.success) {
                    let successMessage = vodpress.i18n.submitSuccess;
                    // Add queue position information if available
                    if (response.data && response.data.queue_position > 1) {
                        successMessage += ' ' + (vodpress.i18n.queuedAt || 'Video was added to the queue at position #') + response.data.queue_position;
                    }
                    $status.html('<div class="notice notice-success"><p>' + successMessage + '</p></div>');
                    $form.find('#video_url').val('');
                    $form.find('#video_title').val('');
                    setTimeout(function () { $status.empty(); updateVideosStatus(); }, 2000);
                } else {
                    $status.html('<div class="notice notice-error"><p>' + (response.data.message || vodpress.i18n.submitError) + '</p></div>');
                }
            },
            error: function () {
                $status.html('<div class="notice notice-error"><p>' + vodpress.i18n.submitError + '</p></div>');
            }
        });
    });

    $('#vodpress-search-button, #vodpress-clear-search').off('click');
    
    $('#vodpress-search').on('input', function() {
        clearTimeout(searchTimeout);
  
        searchTimeout = setTimeout(function() {
            searchTerm = $('#vodpress-search').val();
            updateVideosStatus();
        }, 500);
    });
    
    $('#vodpress-search').off('keypress');

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
            actions += '<button class="button button-small vodpress-delete" data-video-id="' + video.id + '">Delete</button> ';
            // add retry button if video is failed or pending
            if (video.status === 'failed' || video.status === 'pending') {
                actions = '<button class="button button-small vodpress-retry" data-video-id="' + video.id + '">Retry</button> ' + actions;
            }

            var errorMessage = video.error_message ? '<br><small style="color: #dc3232;">' + video.error_message + '</small>' : '';
            
            var conversionUrlColumn = '';
            if (video.conversion_url) {
                conversionUrlColumn = '<a href="javascript:void(0);" class="vodpress-view-video" data-url="' + video.conversion_url + '" data-title="' + video.title + '">View</a> | ';
                conversionUrlColumn += '<a href="javascript:void(0);" class="vodpress-copy-url" data-url="' + video.conversion_url + '">Copy URL</a>';
            } else {
                conversionUrlColumn = '-';
            }

            var videoUrlDisplay = '';
            videoUrlDisplay = '<a href="javascript:void(0);" class="vodpress-view-video" data-url="' + video.video_url + '" data-title="' + video.title + '">' + 
                    video.video_url.substring(0, 30) + (video.video_url.length > 30 ? '...' : '') + '</a>';
                    
            $tbody.append(
                '<tr>' +
                '<td>' + video.id + '</td>' +
                '<td>' + video.title + '</td>' +
                '<td>' + videoUrlDisplay + '</td>' +
                '<td><span class="vodpress-status-' + video.status + '">' + video.status_label + '</span>' + errorMessage + '</td>' +
                '<td>' + video.created_at + '</td>' +
                '<td>' + video.updated_at + '</td>' +
                '<td>' + conversionUrlColumn + '</td>' +
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

        $('.vodpress-view-video').off('click').on('click', function() {
            var videoUrl = $(this).data('url');
            var videoTitle = $(this).data('title');
            
            openVideoModal(videoUrl, videoTitle);
        });
        
        $('.vodpress-close').off('click').on('click', function() {
            closeVideoModal();
        });
        
        $(window).off('click').on('click', function(event) {
            if ($(event.target).is('#vodpress-video-modal')) {
                closeVideoModal();
            }
        });
    }

    function openVideoModal(videoUrl, videoTitle) {
        $('#vodpress-video-title').text(videoTitle);
        $('#vodpress-video-player').empty();
        
        $('#vodpress-video-modal').css('display', 'flex');
        
        initVideoPlayer(videoUrl);
    }

    function closeVideoModal() {
        $('#vodpress-video-modal').css('display', 'none');
        $('#vodpress-video-player').empty();
    }

    // initialize the video player 
    async function initVideoPlayer(videoUrl) {
        try {
            const playerBox = document.getElementById('vodpress-video-player');
            
            if (!playerBox) {
                console.error("Player container not found!");
                return;
            }
            
            // Check video type (HLS or regular)
            const isHLS = videoUrl.includes('.m3u8');
            
            if (isHLS) {
                // Use Vidstack for HLS videos
                const { PlyrLayout, VidstackPlayer } = await import('https://cdn.vidstack.io/player');
                
                const player = await VidstackPlayer.create({
                    viewType: "video",
                    target: playerBox,
                    title: $('#vodpress-video-title').text(),
                    streamType: "on-demand",
                    crossOrigin: true,
                    playsInline: true,
                    src: videoUrl,
                    layout: new PlyrLayout(),
                });
                
                
                player.addEventListener('loaded-metadata', () => {                    
                    const videoElement = playerBox.querySelector('video');
                    
                    if (videoElement) {
                        const videoWidth = videoElement.videoWidth;
                        const videoHeight = videoElement.videoHeight;
                        
                        
                        if (videoHeight > videoWidth) {
                            playerBox.classList.add('plyr--vertical');
                        } else {
                            playerBox.classList.remove('plyr--vertical');
                        }
                    }
                });
                
                player.addEventListener('error', (event) => {
                    console.error('Player error:', event);
                    $('#vodpress-video-player').html('<p>Error loading video. Please try again.</p>');
                });
            } else {                
                // Create video element
                const videoElement = document.createElement('video');
                videoElement.controls = true;
                videoElement.autoplay = false;
                videoElement.playsInline = true;
                videoElement.style.width = '100%';
                videoElement.style.height = '100%';
                videoElement.style.maxHeight = '80vh';
                
                const sourceElement = document.createElement('source');
                sourceElement.src = videoUrl;
                sourceElement.type = getVideoMimeType(videoUrl);
                videoElement.appendChild(sourceElement);
                
                videoElement.innerHTML += '<p>Your browser does not support playing this video.</p>';
                
                // Clear previous content and add the video
                playerBox.innerHTML = '';
                playerBox.appendChild(videoElement);
                
                // Check video dimensions after metadata is loaded
                videoElement.addEventListener('loadedmetadata', function() {
                    console.log('Video loaded, checking dimensions');
                    const videoWidth = this.videoWidth;
                    const videoHeight = this.videoHeight;
                    
                    
                    if (videoHeight > videoWidth) {
                        playerBox.classList.add('plyr--vertical');
                    } else {
                        playerBox.classList.remove('plyr--vertical');
                    }
                });
                
                videoElement.addEventListener('error', function() {
                    console.error('HTML5 Video error');
                    playerBox.innerHTML = '<p>Error loading video. Please try again.</p>';
                });
            }
            
        } catch (error) {
            console.error('Error initializing video player:', error);
            $('#vodpress-video-player').html('<p>Error loading video player. Please try again.</p>');
        }
    }

    
    function getVideoMimeType(url) {
        const extension = url.split('.').pop().toLowerCase();
        const mimeTypes = {
            'mp4': 'video/mp4',
            'webm': 'video/webm',
            'ogg': 'video/ogg',
            'mov': 'video/quicktime',
            'avi': 'video/x-msvideo',
            'wmv': 'video/x-ms-wmv',
            'flv': 'video/x-flv',
            '3gp': 'video/3gpp',
            'mkv': 'video/x-matroska'
        };
        
        return mimeTypes[extension] || 'video/mp4'; // Default video/mp4
    }

    // Check for updates every 5 seconds
    setInterval(updateVideosStatus, 5000);

    // Initial update on page load
    updateVideosStatus();
});