(function ($, Drupal, drupalSettings, once) {
    Drupal.behaviors.tlsChecker = {
        attach: function (context, settings) {
            console.log("📌 JavaScript Loaded: tlsChecker attached.");

            const scanButton = $("input#tls-start-scan");
            const resetButton = $("input#tls-reset-scan");

            // Ensure Scan Button is properly bound
            once('tlsScanAttach', '#tls-start-scan', context).forEach((element) => {
                element.addEventListener('click', function (e) {
                    e.preventDefault();
                    console.log("🚀 AJAX TLS Scan Triggered...");

                    // Disable buttons while scanning.
                    scanButton.prop("disabled", true);
                    resetButton.prop("disabled", true);

                    $('#tls-scan-progress').show();
                    $('.tls-progress').css("width", "0%");  // Reset progress bar
                    $('#tls-progress-text').text("Fetching URLs...").show();

                    // Fetch URLs to scan before sending to the batch process
                    $.ajax({
                        url: Drupal.url('tls_checker/get_urls'),
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
                                // Re-enable buttons if no URLs were found
                                scanButton.prop("disabled", false);
                                resetButton.prop("disabled", false);                                
                            }
                        },
                        error: function (xhr, status, error) {
                            console.error("❌ Error fetching URLs:", xhr.responseText, status, error);
                            alert("Error fetching URLs to scan.");
                            // Re-enable buttons in case of error
                            scanButton.prop("disabled", false);
                            resetButton.prop("disabled", false);                            
                        }
                    });
                });
            });

            function tlsCheckerProcessBatch(urlsToScan, batchSize = 10, offset = 0) {
				let totalUrls = urlsToScan.length;
				let batch = urlsToScan.slice(offset, offset + batchSize);
                console.log(`📡 Sending batch (${offset + 1} - ${offset + batchSize}) of ${totalUrls}...`, batch);

                $.ajax({
                    url: Drupal.url('tls_checker/process_batch'),
                    type: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({ urls: batch, _format: 'json' }), // Batch URLs in chunks
                    success: function (response) {
                        console.log("✅ AJAX Response (Batch Processed):", response);

						let processedSoFar = offset + batch.length;
						let progress = Math.round((processedSoFar / totalUrls) * 100);

						$(".tls-progress").css("width", progress + "%").show();
						$("#tls-progress-text").fadeIn(200).text(`${progress}%`);

						if ( progress >= 50 ) {
							$("#tls-progress-text").css("color", "white");
						}

						// If there are still more batches, continue processing.
                        if (processedSoFar < totalUrls) {
							console.log("🔄 Continuing with next batch...");
							setTimeout(() => tlsCheckerProcessBatch(urlsToScan, batchSize, processedSoFar), 500);
						} else {
							console.log("🏁 Scan complete!");
							fetchFinalResults();
							setTimeout(() => {
								$("#tls-scan-progress").fadeOut();
                                // Re-enable buttons after scan completes
                                scanButton.prop("disabled", false);
                                resetButton.prop("disabled", false);                                
							}, 3000);
						}
                    },
                    error: function (xhr, status, error) {
                        console.error("❌ Error processing TLS scan batch:", xhr.responseText);
                        alert("❌ Error processing TLS scan batch.");
                        // Re-enable buttons in case of error
                        scanButton.prop("disabled", false);
                        resetButton.prop("disabled", false);                        
                    }
                });
            }

			function fetchFinalResults() {
				$.ajax({
					url: Drupal.url('tls_checker/get_results'),
					type: 'GET',
					dataType: 'json',
					success: (response) => {
						console.log("✅ Final scan results:", response);

						let passingCount = typeof response.passing !== 'undefined' ? response.passing : 0;
						let failingCount = typeof response.failing !== 'undefined' ? response.failing : 0;
						let resultHTML = "<div class='messages messages--status'>";
						resultHTML += "<p><strong>Scan Complete!</strong></p>";
						resultHTML += `<p>Passing Domains:${passingCount}</p>`;
						resultHTML += `<p>Failing Domains: ${failingCount}</p>`;

						if (response.failing_urls.length > 0) {
							resultHTML += "<p><strong>Failing Domains:</strong></p><ul>";
							response.failing_urls.forEach(url => {
								resultHTML += `<li>${url}</li>`;
							});
							resultHTML += "</ul>";
						}
						resultHTML += "</div>";

						$("#tls-scan-progress").after(resultHTML);
						$(".tls-progress").css("width: 100%");
						$("#tls-progress-text").text("Scan complete!");
					},
					error: (xhr) => {
						console.error("❌ Error fetching final results:", xhr.responseText);
						alert("Error fetching final scan results.");
					}
				});
			}

            // Ensure Reset Button is properly bound (DO NOT REMOVE)
            once('resetScanAttach', '#tls-reset-scan', context).forEach((element) => {
                element.addEventListener('click', function (e) {
                    e.preventDefault(); // Prevent default page reload

                    if (!confirm('Are you sure you want to reset the scan data?')) {
                        return;
                    }

					// Process the ajax request to reset the scan data
                    $.ajax({
                        url: Drupal.url('tls_checker/reset_data'),
                        type: 'POST',
                        dataType: 'json'
					}).done((data) => {
						console.log("🔄 Scan Data Reset:", data);

						// Reload the page.
						window.location.href = window.location.href;						
					}).fail((xhr) => {
						console.error("❌ Error resetting scan data:", xhr);
						alert('An error occurred while resetting scan data.');
					});
                });
            });
        }
    };
})(jQuery, Drupal, drupalSettings, once);
