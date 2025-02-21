(function ($, Drupal, drupalSettings, once) {
    Drupal.behaviors.tlsChecker = {
        attach: function (context, settings) {
            console.log("📌 JavaScript Loaded: tlsChecker attached.");

            // Ensure Scan Button is properly bound
            once('tlsScanAttach', '#tls-start-scan', context).forEach((element) => {
                element.addEventListener('click', function (e) {
                    e.preventDefault();
                    console.log("🚀 AJAX TLS Scan Triggered...");

                    $('#tls-scan-progress').show();
                    $('.tls-progress').css("width", "0%");  // Reset progress bar
                    $('#tls-progress-text').text("Fetching URLs...");

                    // Fetch URLs to scan before sending to the batch process
                    $.ajax({
                        url: Drupal.url('admin/config/development/tls-checker/get-urls'),
                        type: 'GET',
                        dataType: 'json',
                        success: function (data) {
                            console.log("✅ AJAX Response (Fetching URLs):", data);

							let urls = Object.values(data.urls_to_scan || {});

                            if ( urls.length > 0) {
                                console.log("🔍 URLs to scan:", urls);
                                tlsCheckerProcessBatch(urls);
                            } else {
                                console.warn("⚠️ No URLs found to scan.");
                                $("#tls-progress-text").text("No URLs found.").show();
                            }
                        },
                        error: function (xhr, status, error) {
                            console.error("❌ Error fetching URLs:", xhr.responseText, status, error);
                            alert("Error fetching URLs to scan.");
                        }
                    });
                });
            });

            function tlsCheckerProcessBatch(urlsToScan) {
                console.log("📡 Sending batch scan request...", urlsToScan);

                $.ajax({
                    url: Drupal.url('tls_checker/process_batch'),
                    type: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({ urls: urlsToScan, _format: 'json' }), // Send URLs properly
                    success: function (response) {
                        console.log("✅ AJAX Response (Batch Processed):", response);

                        if (response.processed > 0) {
                            let progress = Math.round((response.processed / urlsToScan.length) * 100);
                            console.log(`📊 Updating Progress: ${progress}%`);

                            $(".tls-progress").css("width", progress + "%");
                            $("#tls-progress-text").text(`Progress: ${progress}%`).show();

                            if (response.remaining > 0) {
                                console.log("🔄 Continuing scan...");
                                setTimeout(() => tlsCheckerProcessBatch(urlsToScan), 500);
                            } else {
                                console.log("🏁 Scan complete!");
                                $(".tls-progress").css("width", "100%");
                                $("#tls-progress-text").text("Scan complete!");

                                let resultHTML = "<div class='messages messages--status'>";
                                resultHTML += `<p><strong>Scan complete!</strong></p>`;
                                resultHTML += `<p>Passing Domains: ${response.passing}</p>`;
                                resultHTML += `<p>Failing Domains: ${response.failing}</p>`;

                                if (response.failing_urls.length > 0) {
                                    resultHTML += `<p><strong>Failing Domains:</strong></p><ul>`;
                                    response.failing_urls.forEach(url => {
                                        resultHTML += `<li>${url}</li>`;
                                    });
                                    resultHTML += `</ul>`;
                                }
                                resultHTML += `</div>`;

                                $("#tls-scan-progress").after(resultHTML);
                                setTimeout(() => {
                                    $("#tls-scan-progress").fadeOut();
                                }, 3000);
                            }
                        } else {
                            console.warn("⚠️ No scan results received.");
                            $("#tls-progress-text").text("No scan results received.").show();
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error("❌ Error processing TLS scan batch:", xhr.responseText);
                        alert("❌ Error processing TLS scan batch.");
                    }
                });
            }

            // ✅ Ensure Reset Button is properly bound (DO NOT REMOVE)
            once('resetScanAttach', '#tls-reset-scan', context).forEach((element) => {
                element.addEventListener('click', function (e) {
                    e.preventDefault(); // ✅ Prevent default page reload

                    if (!confirm('Are you sure you want to reset the scan data?')) {
                        return;
                    }

                    $.ajax({
                        url: Drupal.url('admin/config/tls_checker/reset-scan'),
                        type: 'POST',
                        dataType: 'json',
                        success: function (data) {
                            console.log("🔄 Scan Data Reset:", data);
                            alert(data.message);

                            // ✅ Hide progress bar and reset UI
                            $('#tls-scan-progress').hide();
                            $('.tls-progress').css("width", "0%");
                            $('#tls-progress-text').text("0%");
                        },
                        error: function (xhr) {
                            console.error("❌ Error resetting scan data:", xhr);
                            alert('An error occurred while resetting scan data.');
                        }
                    });
                });
            });
        }
    };
})(jQuery, Drupal, drupalSettings, once);
