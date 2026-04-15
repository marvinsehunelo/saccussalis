function showResponse(message, type = "info") {
    const box = document.getElementById("responseBox");
    box.className = `response-box ${type}`;
    box.textContent = message;
}

function setAmount(fieldId, amount) {
    document.getElementById(fieldId).value = amount;
}

function updateClock() {
    const now = new Date();
    document.getElementById("screenTime").textContent = now.toLocaleTimeString();
}

setInterval(updateClock, 1000);
updateClock();

document.querySelectorAll(".side-btn[data-tab]").forEach(button => {
    button.addEventListener("click", () => {
        document.querySelectorAll(".side-btn[data-tab]").forEach(btn => btn.classList.remove("active"));
        document.querySelectorAll(".tab-panel").forEach(panel => panel.classList.remove("active"));

        button.classList.add("active");
        document.getElementById(button.dataset.tab).classList.add("active");
        showResponse("Ready for transaction.", "info");
    });
});

async function cashoutEwallet() {
    const atm_id = parseInt(document.getElementById("atm_id").value, 10);
    const phone = document.getElementById("phone").value.trim();
    const pin = document.getElementById("ewallet_pin").value.trim();
    const amount = parseFloat(document.getElementById("ewallet_amount").value);

    if (!phone || !pin || !amount) {
        showResponse("Fill in phone, PIN, and amount.", "error");
        return;
    }

    showResponse("Processing ewallet cashout...", "info");

    try {
        const res = await fetch("/backend/atm/ewallet_cashout.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ atm_id, phone, pin, amount })
        });

        const data = await res.json();

        if (data.status === "APPROVED") {
            showResponse(`Cashout approved. Reference: ${data.reference}. Amount: P${data.amount}`, "success");
        } else {
            showResponse(data.message || "Ewallet cashout failed.", "error");
        }
    } catch (err) {
        showResponse("Network or server error during ewallet cashout.", "error");
        console.error(err);
    }
}

async function authorizeSAT() {
    const atm_id = parseInt(document.getElementById("atm_id").value, 10);
    const sat_number = document.getElementById("sat_number").value.trim();
    const pin = document.getElementById("sat_pin").value.trim();
    const amount = parseFloat(document.getElementById("sat_amount").value);

    if (!sat_number || !pin || !amount) {
        showResponse("Fill in SAT number, PIN, and amount.", "error");
        return;
    }

    showResponse("Authorizing SAT...", "info");

    try {
        const res = await fetch("/backend/atm/sat_cashout.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ atm_id, sat_number, pin, amount })
        });

        const data = await res.json();

        if (data.status === "APPROVED") {
            document.getElementById("trace_number").value = data.trace_number || "";
            showResponse(`SAT authorized. Trace: ${data.trace_number}. Now press Dispense / Complete.`, "success");
        } else {
            showResponse(data.message || "SAT authorization failed.", "error");
        }
    } catch (err) {
        showResponse("Network or server error during SAT authorization.", "error");
        console.error(err);
    }
}

async function completeSAT() {
    const atm_id = parseInt(document.getElementById("atm_id").value, 10);
    const sat_number = document.getElementById("sat_number").value.trim();
    const trace_number = document.getElementById("trace_number").value.trim();

    if (!sat_number || !trace_number) {
        showResponse("Authorize SAT first before completion.", "error");
        return;
    }

    showResponse("Completing SAT cashout...", "info");

    try {
        const res = await fetch("/backend/atm/sat_complete.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ atm_id, sat_number, trace_number })
        });

        const data = await res.json();

        if (data.status === "COMPLETED") {
            showResponse(`SAT cashout completed. Reference: ${data.reference}. Amount: P${data.amount}`, "success");
            document.getElementById("trace_number").value = "";
        } else {
            showResponse(data.message || "SAT completion failed.", "error");
        }
    } catch (err) {
        showResponse("Network or server error during SAT completion.", "error");
        console.error(err);
    }
}
