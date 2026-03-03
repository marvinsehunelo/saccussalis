async function cashoutEwallet() {

    const phone = document.getElementById("phone").value;
    const pin = document.getElementById("pin").value;
    const amount = document.getElementById("amount").value;

    const res = await fetch("/backend/atm/ewallet_cashout.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ phone, pin, amount })
    });

    const data = await res.json();
    alert(data.message);
}

async function cashoutSAT() {

    const sat = document.getElementById("sat").value;
    const amount = document.getElementById("amount").value;

    const res = await fetch("/backend/atm/sat_cashout.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ sat, amount })
    });

    const data = await res.json();
    alert(data.status);
}
