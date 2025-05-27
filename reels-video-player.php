<?php
/**
 * Plugin Name: Reels Video Player
 * Description: Display vertical, swipeable grouped video reels using self-hosted videos.
 * Version: 1.0
 * Author: <a href="https://xplication.com">Xplication.com</a>
 */

// Register shortcode
add_shortcode('reels_videos', function($atts) {
    $atts = shortcode_atts(['group' => ''], $atts);
    $all_groups = get_option('grouped_reels_data', []);
    $videos_to_show = [];

    foreach ($all_groups as $group) {
        if ($group['slug'] === $atts['group']) {
            $videos_to_show = $group['videos'];
            break;
        }
    }

    if (empty($videos_to_show)) return '<p>No videos in this group.</p>';

    ob_start();
    ?>
    <style>
    .video-reel-container {
        height: 100vh;
        overflow-y: scroll;
        scroll-snap-type: y mandatory;
    }
    .video-slide {
        height: 100vh;
        scroll-snap-align: start;
        position: relative;
        background: #000;
    }
    .video-slide video {
        object-fit: cover;
        width: 100%;
        height: 100%;
        display: block;
    }
    .play-overlay {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 80px;
        height: 80px;
        background: rgba(0,0,0,0.6);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 2;
        cursor: pointer;
    }
    .play-overlay::before {
        content: '';
        display: block;
        width: 0;
        height: 0;
        border-left: 25px solid white;
        border-top: 15px solid transparent;
        border-bottom: 15px solid transparent;
        margin-left: 5px;
    }
    .hide { display: none !important; }
    </style>

    <div class="video-reel-container">
        <?php foreach ($videos_to_show as $video_url): ?>
            <div class="video-slide">
                <video playsinline muted preload="none" data-src="<?php echo esc_url($video_url); ?>"></video>
                <div class="play-overlay"></div>
            </div>
        <?php endforeach; ?>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const slides = document.querySelectorAll('.video-slide');
        let userInteracted = false;
        let currentlyPlaying = null;

        const loadVideoSrc = (video) => {
            if (!video.src && video.dataset.src) {
                video.src = video.dataset.src;
            }
        };

        const playVideo = (video, overlay) => {
            loadVideoSrc(video);
            video.play().then(() => {
                overlay.classList.add('hide');
                currentlyPlaying = video;
            }).catch(() => {
                overlay.classList.remove('hide');
            });
        };

        const pauseVideo = (video, overlay) => {
            video.pause();
            overlay.classList.remove('hide');
        };

        slides.forEach(slide => {
            const video = slide.querySelector('video');
            const overlay = slide.querySelector('.play-overlay');

            overlay.addEventListener('click', () => {
                userInteracted = true;
                if (video.paused) {
                    if (currentlyPlaying && currentlyPlaying !== video) {
                        pauseVideo(currentlyPlaying, currentlyPlaying.parentElement.querySelector('.play-overlay'));
                    }
                    playVideo(video, overlay);
                } else {
                    pauseVideo(video, overlay);
                }
            });

            video.addEventListener('click', () => {
                if (video.paused) {
                    overlay.click();
                } else {
                    pauseVideo(video, overlay);
                }
            });

            const observer = new IntersectionObserver(entries => {
                entries.forEach(entry => {
                    if (entry.isIntersecting && userInteracted) {
                        if (video.paused) {
                            if (currentlyPlaying && currentlyPlaying !== video) {
                                pauseVideo(currentlyPlaying, currentlyPlaying.parentElement.querySelector('.play-overlay'));
                            }
                            playVideo(video, overlay);
                        }
                    } else {
                        if (!video.paused) {
                            pauseVideo(video, overlay);
                        }
                    }
                });
            }, { threshold: 0.8 });

            observer.observe(slide);
        });
    });
    </script>
    <?php
    return ob_get_clean();
});

// Main menu + submenu
add_action('admin_menu', function () {
    add_menu_page(
        'Reels Video Player', 'Reels Video Player', 'manage_options', 'reels-video-player', '__return_null', 'dashicons-controls-play', 25
    );
    add_submenu_page(
        'reels-video-player', 'Grouped Reels', 'Grouped Reels', 'manage_options', 'grouped-reels', 'render_grouped_reels_admin'
    );
});

add_action('admin_enqueue_scripts', function($hook) {
    if (isset($_GET['page']) && $_GET['page'] === 'grouped-reels') {
        wp_enqueue_media();
    }
});

function render_grouped_reels_admin() {
    $data = get_option('grouped_reels_data', []);
    $data_json = json_encode($data);
    ?>
    <div class=\"wrap\">
        <p style="max-width: 600px; font-size: 15px;">Use this interface to manage groups of video reels. Each group represents a vertical video playlist that can be embedded using the shortcode. After adding videos, copy the shortcode shown near the group name and paste it into any page or Elementor section.</p>
        <h1>Grouped Video Reels</h1>
        <form method="post" id="grouped-reels-form">
            <div id="reels-container"></div>
            <p>
                <button type="button" class="button button-primary" id="add-group">Add Group</button>
                <button type="submit" class="button button-secondary">Save</button>
            </p>
        </form>
    </div>

    <style>
        .group-block { margin-bottom: 20px; padding: 10px; background: #fff; border: 1px solid #ccc; }
        .video-list input { width: 100%; margin-bottom: 5px; }
    </style>

    <script>
    document.addEventListener("DOMContentLoaded", function () {
        const container = document.getElementById("reels-container");
        const data = <?php echo $data_json ?: '[]'; ?>;

        function createGroupBlock(groupSlug = '', videoUrls = []) {
            const block = document.createElement("div");
            block.className = "group-block";
            block.innerHTML = `
                <label><strong>Group Slug:</strong>
                    <input type="text" name="group[]" value="${groupSlug}" />
                </label>
                <div style="margin-top: 5px;">
                    <code style="display:block; background:#f3f3f3; padding:4px 8px; border:1px solid #ccc;">[reels_videos group="${groupSlug}"]</code>
                </div>
                <div class="video-list"></div>
                <button type="button" class="button add-video">Add Video</button>
                <hr />
            `;

            const videoList = block.querySelector(".video-list");
            const addVideoBtn = block.querySelector(".add-video");

            videoUrls.forEach(url => {
                addVideoInput(videoList, url);
            });

            addVideoBtn.addEventListener("click", () => {
                const frame = wp.media({
                    title: "Select Videos",
                    button: { text: "Use selected videos" },
                    multiple: true
                });

                frame.on("select", function () {
                    const selection = frame.state().get("selection");
                    selection.each(function (attachment) {
                        addVideoInput(videoList, attachment.toJSON().url);
                    });
                });

                frame.open();
            });

            container.appendChild(block);
        }

        function addVideoInput(container, url) {
            const wrapper = document.createElement("div");
            wrapper.style.display = "flex";
            wrapper.style.gap = "10px";
            wrapper.style.alignItems = "center";
            wrapper.style.marginBottom = "5px";

            const input = document.createElement("input");
            input.type = "text";
            input.className = "video-url";
            input.value = url;
            input.readOnly = true;

            const delBtn = document.createElement("button");
            delBtn.type = "button";
            delBtn.className = "button";
            delBtn.textContent = "Delete";
            delBtn.addEventListener("click", () => {
                wrapper.remove();
            });

            wrapper.appendChild(input);
            wrapper.appendChild(delBtn);
            container.appendChild(wrapper);
        }

        data.forEach(group => createGroupBlock(group.slug, group.videos));

        document.getElementById("add-group").addEventListener("click", () => {
            createGroupBlock();
        });

        document.getElementById("grouped-reels-form").addEventListener("submit", function (e) {
            e.preventDefault();

            const form = e.target;
            const groups = form.querySelectorAll(".group-block");
            const output = [];

            groups.forEach(group => {
                const slug = group.querySelector('input[name^="group"]')?.value.trim();
                const videos = Array.from(group.querySelectorAll(".video-url")).map(i => i.value.trim()).filter(Boolean);
                if (slug && videos.length) {
                    output.push({ slug, videos });
                }
            });

            fetch(ajaxurl, {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: `action=save_grouped_reels&data=${encodeURIComponent(JSON.stringify(output))}`
            })
            .then(res => res.json())
            .then(response => {
                alert(response.data || "Saved successfully!");
            });
        });
    });
    </script>
    <?php
}

add_action('wp_ajax_save_grouped_reels', function () {
    if (current_user_can('manage_options') && isset($_POST['data'])) {
        update_option('grouped_reels_data', json_decode(stripslashes($_POST['data']), true));
        wp_send_json_success("Grouped reels saved successfully!");
    } else {
        wp_send_json_error("Not allowed");
    }
});
