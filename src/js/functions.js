
var ropaniInput = document.getElementById('ropani-input');
var annaInput = document.getElementById('anna-input');
var paisaInput = document.getElementById('paisa-input');
var daamInput = document.getElementById('daam-input');

var bighaInput = document.getElementById('bigha-input');
var katthaInput = document.getElementById('kattha-input');
var dhurpInput = document.getElementById('dhur-input');

var squarefeetInput = document.getElementById('sq-feet-input');
var squaremeterInput = document.getElementById('sq-meter-input');

// Close Popup
function closePopup() {
    document.getElementById("modal").style.display = "none";
}

/*For the Area converter tool*/ 
function formatDecimal(value) {
    return value.toFixed(3);
}
function convertSqMetertoSqFeet(sqmeter) {
    return (sqmeter * 10.764);
}

function convertSqFeetToSqMeter(sqfeet) {
    return (sqfeet / 10.764);
}

function convertFromRopani() {
    let sqmeter = (ropaniInput.value * 508.72) + (annaInput.value * 31.80) + (paisaInput.value * 7.95) + (daamInput.value * 1.99);

    showBighaSystem(sqmeter);
    showFeetSystem(sqmeter);
    showMeterSystem(sqmeter);
}
function convertFromBigha() {
    let sqmeter = (bighaInput.value * 6772.63) + (katthaInput.value * 338.63) + (dhurpInput.value * 16.93);

    showRopaniSystem(sqmeter);
    showFeetSystem(sqmeter);
    showMeterSystem(sqmeter);
}
function convertFromSquareFeet() {
    let sqmeter = convertSqFeetToSqMeter(squarefeetInput.value);

    showRopaniSystem(sqmeter);
    showBighaSystem(sqmeter);
    showMeterSystem(sqmeter);
}

function convertFromSquareMeter() {
    let sqmeter = squaremeterInput.value;

    showRopaniSystem(sqmeter);
    showBighaSystem(sqmeter);
    showFeetSystem(sqmeter);
}
function showRopaniSystem(sqmeter) {
    let ropani = parseInt(sqmeter / 508.74);
    sqmeter = sqmeter % 508.74;
    let anna = parseInt(sqmeter / 31.80);
    sqmeter = sqmeter % 31.80;
    let paisa = parseInt(sqmeter / 7.95);
    sqmeter = sqmeter % 7.95;
    let daam = sqmeter / 1.99;

    ropaniInput.value = ropani;
    annaInput.value = anna;
    paisaInput.value = paisa;
    daamInput.value = formatDecimal(daam);
}

function showBighaSystem(sqmeter) {
    let bigha = parseInt(sqmeter / 6772.41);
    sqmeter = sqmeter % 6772.41;
    let kattha = parseInt(sqmeter / 338.62);
    sqmeter = sqmeter % 338.62;
    let dhur = sqmeter / 16.93;

    bighaInput.value = bigha;
    katthaInput.value = kattha;
    dhurpInput.value = formatDecimal(dhur);
}

function showFeetSystem(sqmeter) {
    squarefeetInput.value = formatDecimal(convertSqMetertoSqFeet(sqmeter));
}

function showMeterSystem(sqmeter) {
    squaremeterInput.value = formatDecimal(sqmeter);
}

function clearAll() {
    document.querySelectorAll('input').forEach(input => input.value = '');
}