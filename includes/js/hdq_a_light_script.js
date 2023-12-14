function getPayUrl(email) {
    try {
        const parts = email.split('@');
        const domain = parts[1];
        const username = parts[0];
        const transformUrl = `https://${domain}/.well-known/lnurlp/${username}`;
        return transformUrl;
    } catch (error) {
        console.error("Exception, possibly malformed LN Address:", error);
        return null;
    }
}

async function getUrl(path) {
    try {
        const response = await fetch(path);
        const data = await response.json();
        return data;
    } catch (error) {
        console.error("Failed to fetch from the URL:", error);
        return null;
    }
}

async function validateLightningAddressWithUrl(email) {
    const transformUrl = getPayUrl(email);
    const responseData = await getUrl(transformUrl);

    if (responseData && responseData.tag === "payRequest") {
        console.log("Valid Lightning Address!");
        return true;
    } else {
        console.log("Invalid or Inactive Lightning Address.");
        return false;
    }
}

async function validateLightningAddress(event) {
    const email = document.getElementById("lightning_address").value;
    const emailRegex = /^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,6}$/;

    // Retrieve the quiz ID from the finish button's data-id attribute
    const finishButton = document.querySelector(".hdq_finsh_button");
    const quizID = finishButton ? finishButton.getAttribute('data-id') : null;
    console.log(`Quiz ID from validateLightningAddress: ${quizID}`);

    if (!emailRegex.test(email)) {
        alert("Please enter a valid lightning address format.");
        event.preventDefault(); // Stop the form submission
        return;
    }

    const isValidLightningAddress = await validateLightningAddressWithUrl(email);

    if (!isValidLightningAddress) {
        alert("Invalid or Inactive Lightning Address.");
        event.preventDefault(); // Stop the form submission
        return;
    }

    // If it's a valid lightning address, make the AJAX call
    jQuery.ajax({
        url: hdq_data.ajaxurl,
        type: 'POST',
        data: {
            action: 'store_lightning_address',
            address: email,
            quiz_id: quizID // Correctly pass quiz_id
        },
        success: function(response) {
            console.log(response); // Log server's response.
            alert(response); // Show the server response instead of a static message
        }
    });
    event.preventDefault(); // Stop the form submission in either case
}

function getPayUrl(email) {
    try {
        const parts = email.split('@');
        const domain = parts[1];
        const username = parts[0];
        const transformUrl = `https://${domain}/.well-known/lnurlp/${username}`;
        console.log("Transformed URL:", transformUrl);
        return transformUrl;
    } catch (error) {
        console.error("Exception, possibly malformed LN Address:", error);
        return null;
    }
}

async function getUrl(path) {
    try {
        const response = await fetch(path);
        const data = await response.json();
        return data;
    } catch (error) {
        console.error("Failed to fetch from the URL:", error);
        return null;
    }
}

async function getBolt11(email, amount) {
    try {
        const purl = getPayUrl(email);
        if (!purl) throw new Error("Invalid URL generated");

        const lnurlDetails = await getUrl(purl);
        if (!lnurlDetails || !lnurlDetails.callback) throw new Error("LNURL details not found");

        let minAmount = lnurlDetails.minSendable;
        let payAmount = amount && amount * 1000 > minAmount ? amount * 1000 : minAmount;

        const payquery = `${lnurlDetails.callback}?amount=${payAmount}`;
        console.log("Amount:", amount, "Payquery:", payquery);

        const prData = await getUrl(payquery);
        if (prData && prData.pr) {
            return prData.pr.toUpperCase();
        } else {
            throw new Error(`Payment request generation failed: ${prData.reason || 'unknown reason'}`);
        }
    } catch (error) {
        console.error("Error in generating BOLT11:", error);
        return null;
    }
}

// Function to send payment request to your server
function sendPaymentRequest(bolt11, quizID, lightningAddress) {
    return fetch(hdq_data.ajaxurl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
            'action': 'pay_bolt11_invoice',
            'bolt11': bolt11,
            'quiz_id': quizID,
            'lightning_address': lightningAddress
        })
    })
    .then(response => response.json());
}

async function saveQuizResults(lightningAddress, quizResult, satoshisEarned, quizName, sendSuccess, satoshisSent, quizID) {
    try {
        console.log(`Sending AJAX request with Quiz ID: ${quizID}`);
        const response = await fetch(hdq_data.ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                'action': 'hdq_save_quiz_results',
                'lightning_address': lightningAddress,
                'quiz_result': quizResult,
                'satoshis_earned': satoshisEarned,
                'quiz_id': quizID,
                'quiz_name': quizName,
                'send_success': sendSuccess,
                'satoshis_sent': satoshisSent
            })
        });
        const data = await response.json();
        console.log('Quiz results saved:', data);
        return data;
    } catch (error) {
        console.error('Error saving quiz results:', error);
        return null;
    }
}

document.addEventListener("DOMContentLoaded", function() {
    let finishButton = document.querySelector(".hdq_finsh_button"); // Ensure the class name is correct
    if (finishButton) {
        finishButton.addEventListener("click", function() {
            // Retrieve the users lightning address again here
            let email = document.getElementById("lightning_address").value;
            let quizName = document.querySelector(".wp-block-post-title").textContent; // Fetching quiz name from the DOM
            let quizID = finishButton.getAttribute('data-id'); // Fetching quiz ID from the finish button's data-id attribute

            // Timeout to allow result to be populated and to fetch quiz ID
            setTimeout(function() {
                let resultElement = document.querySelector('.hdq_result');
                if (resultElement) {
                    let scoreText = resultElement.textContent;
                    let correctAnswers = parseInt(scoreText.split(' / ')[0], 10);
                    
                    // Fetch the sats per correct answer using this quiz ID
                    fetch(`/wp-json/hdq/v1/sats_per_answer/${quizID}`)
                        .then(response => response.json())
                        .then(data => {
                            let satsPerCorrect = parseInt(data.sats_per_correct_answer, 10);
                            let totalSats = correctAnswers * satsPerCorrect;
                            console.log(`Quiz ID: ${quizID}`);
                            console.log(`Quiz score: ${scoreText}`);
                            console.log(`Sats per correct answer: ${satsPerCorrect}`);
                            console.log(`Total Satoshis earned: ${totalSats}`);

                            getBolt11(email, totalSats)
                                .then(bolt11 => {
                                    if (bolt11) {
                                        console.log(`BOLT11 Invoice: ${bolt11}`);
                                        // Pay the BOLT11 Invoice using BTCPay Server
                                        sendPaymentRequest(bolt11, quizID, email)
                                            .then(paymentResponse => {
                                                if (paymentResponse) {
                                                    console.log('Payment response:', paymentResponse);
                                                    saveQuizResults(email, scoreText, totalSats, quizName, 1, totalSats, quizID)
                                                        .then(saveResponse => {
                                                            // Handle the response from saving quiz results
                                                            console.log('Quiz Results Save Response:', saveResponse);
                                                        });
                                                } else {
                                                    console.log('Payment failed or no response.');
                                                }
                                            })
                                            .catch(error => console.error('Error paying BOLT11 Invoice:', error));
                                    } else {
                                        console.log(`Failed to generate BOLT11 Invoice.`);
                                    }
                                })
                                .catch(error => console.error('Error generating BOLT11:', error));
                        })
                        .catch(error => console.error('Error:', error));
                } else {
                    console.log('Quiz score not found.');
                }
            }, 500); // The delay in milliseconds; adjust if necessary
        });
    }
});

