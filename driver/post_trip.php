<?php
require_once __DIR__ . '/../config.php';
require_login(['driver']);

$driver_id = $_SESSION['user_id'];
// Allow multiple postings. Auto-cancellation will be handled during booking phase.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $origin = trim($_POST['origin']);
    $destination = trim($_POST['destination']);
    
    $origin_lat = !empty($_POST['origin_lat']) ? floatval($_POST['origin_lat']) : null;
    $origin_lng = !empty($_POST['origin_lng']) ? floatval($_POST['origin_lng']) : null;
    $destination_lat = !empty($_POST['destination_lat']) ? floatval($_POST['destination_lat']) : null;
    $destination_lng = !empty($_POST['destination_lng']) ? floatval($_POST['destination_lng']) : null;
    
    $departure_time = trim($_POST['departure_time']);
    $estimated_hours = intval($_POST['estimated_hours']);
    $price_total = floatval($_POST['price_total']);
    $description = trim($_POST['description'] ?? '');
    
    // Validations
    if (empty($origin) || empty($destination) || empty($departure_time) || $price_total <= 0) {
        $error = 'Please fill in all required fields and enter a valid price.';
    } elseif (strtotime($departure_time) <= time()) {
        $error = 'Departure time must be in the future.';
    } else {
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO trips (driver_id, origin, destination, origin_lat, origin_lng, destination_lat, destination_lng, description, departure_time, estimated_hours, price_total, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                ");
                $stmt->execute([
                    $driver_id, $origin, $destination, 
                    $origin_lat, $origin_lng, $destination_lat, $destination_lng, 
                    $description, $departure_time, $estimated_hours, $price_total
                ]);
                
                redirect('driver/dashboard.php');
            } catch (PDOException $e) {
                $error = 'Failed to post trip: ' . $e->getMessage();
            }
        } else {
            $error = 'Database connection failed.';
        }
    }
}

include_once __DIR__ . '/../includes/header.php';
?>

<!-- Leaflet Map CSS CDN -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />

<div class="container my-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="d-flex align-items-center mb-4">
                <a href="dashboard.php" class="btn btn-dark btn-sm rounded-pill px-3 me-3"><i class="bi bi-arrow-left"></i></a>
                <h3 class="fw-bold text-white mb-0"><i class="bi bi-car-front-fill text-primary me-2"></i>Post Private Charter</h3>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger border-danger border-opacity-25 bg-danger bg-opacity-10 text-danger rounded-3"><i class="bi bi-exclamation-circle-fill me-2"></i><?php echo $error; ?></div>
            <?php endif; ?>

                <div class="row">
                    <!-- Input Form -->
                    <div class="col-lg-5 mb-4">
                        <div class="card card-custom p-4 shadow">
                            <form action="post_trip.php" method="POST">
                                
                                <div class="mb-3">
                                    <label for="price_total" class="form-label text-muted small">Total Rental Fare (₱)</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-transparent border-secondary text-success fw-bold">₱</span>
                                        <input type="number" name="price_total" id="price_total" class="form-control form-control-custom text-success fw-bold" placeholder="e.g. 2500" min="1" step="0.01" required>
                                    </div>
                                    <small class="text-muted" style="font-size:0.75rem;">Includes dynamic Admin commission of <strong><?php echo COMMISSION_RATE; ?>%</strong>.</small>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-7">
                                        <label for="departure_time" class="form-label text-muted small">Departure Date & Time</label>
                                        <input type="datetime-local" name="departure_time" id="departure_time" class="form-control form-control-custom" required>
                                    </div>
                                    <div class="col-md-5">
                                        <label for="estimated_hours" class="form-label text-muted small">Estimated Travel</label>
                                        <div class="input-group">
                                            <input type="number" name="estimated_hours" id="estimated_hours" class="form-control form-control-custom" value="3" min="1" max="24" required>
                                            <span class="input-group-text bg-transparent border-secondary text-muted">Hours</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Map Pinning Mode Selector -->
                                <div class="mb-3">
                                    <label class="form-label text-muted small d-block">Map Marker Mode</label>
                                    <div class="d-flex gap-2">
                                        <button type="button" id="btn_pin_origin" class="btn btn-outline-custom w-50 py-2 active text-success" style="border-color: var(--accent-success);">
                                            <i class="bi bi-geo-alt-fill me-1 text-success"></i>Pin Pickup Point
                                        </button>
                                        <button type="button" id="btn_pin_dest" class="btn btn-outline-custom w-50 py-2 text-danger" style="border-color: #ef4444;">
                                            <i class="bi bi-pin-map-fill me-1 text-danger"></i>Pin Dropoff Point
                                        </button>
                                    </div>
                                    <small class="text-muted d-block mt-2" style="font-size:0.75rem;"><i class="bi bi-info-circle me-1 text-primary"></i>Toggle a button above, then click anywhere on the map to pin that location.</small>
                                </div>

                                <hr class="border-secondary my-4">

                                <div class="mb-3 position-relative">
                                    <label for="origin" class="form-label text-muted small">Pickup Origin Address</label>
                                    <input type="text" name="origin" id="originInput" class="form-control form-control-custom" placeholder="e.g. Angeles, Pampanga" autocomplete="off" required>
                                    <ul id="originSuggestions" class="list-group position-absolute w-100 shadow m-0 p-0" style="top:100%; left:0; z-index: 9999; display:none; max-height: 200px; overflow-y: auto; border-radius: 0 0 8px 8px; border: 1px solid #495057;"></ul>
                                    <input type="hidden" name="origin_lat" id="origin_lat">
                                    <input type="hidden" name="origin_lng" id="origin_lng">
                                </div>
                                
                                <div class="mb-4 position-relative">
                                    <label for="destination" class="form-label text-muted small">Dropoff Destination Address</label>
                                    <input type="text" name="destination" id="destInput" class="form-control form-control-custom" placeholder="e.g. NAIA Terminal 3, Pasay" autocomplete="off" required>
                                    <ul id="destSuggestions" class="list-group position-absolute w-100 shadow m-0 p-0" style="top:100%; left:0; z-index: 9999; display:none; max-height: 200px; overflow-y: auto; border-radius: 0 0 8px 8px; border: 1px solid #495057;"></ul>
                                    <input type="hidden" name="destination_lat" id="destination_lat">
                                    <input type="hidden" name="destination_lng" id="destination_lng">
                                </div>
                                
                                <div class="mb-4">
                                    <label for="description" class="form-label text-muted small">Trip Description / Additional Notes (Optional)</label>
                                    <textarea name="description" id="description" class="form-control form-control-custom" rows="2" placeholder="e.g. Pets allowed, Inclusive of toll & gas, Max 4 pax for luggage space"></textarea>
                                </div>
                                
                                <button type="submit" class="btn btn-gradient-primary w-100 py-3">
                                    <i class="bi bi-check-circle-fill me-2"></i>Post Trip Availability
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Interactive Map Picker -->
                    <div class="col-lg-7 mb-4">
                        <div class="card card-custom p-3 shadow h-100 d-flex flex-column justify-content-between">
                            <div>
                                <h5 class="fw-bold text-white mb-2"><i class="bi bi-map-fill text-primary me-2"></i>Pin Selector Map</h5>
                                <p class="text-muted small">Double-click or click to place origin/destination pins. Dragging map is supported.</p>
                            </div>
                            
                            <div id="map" style="height: 500px; border-radius:12px;"></div>
                        </div>
                    </div>
                </div>
        </div>
    </div>
</div>

<style>
    /* Suggestions Dropdown Styling */
    .suggestion-item {
        cursor: pointer;
        background-color: #2b3035;
        color: #fff;
        border-color: #495057;
        font-size: 0.9rem;
    }
    .suggestion-item:hover {
        background-color: #343a40;
    }
</style>

<!-- Leaflet Map JS CDN -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Initialize Map centered in Manila / Pampanga region
    var map = L.map('map').setView([14.8964, 120.7303], 9); 

    // Add free OpenStreetMap tile layers
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    // 2. Setup map pinning controllers
    var currentPinMode = 'origin'; // 'origin' or 'destination'
    
    var originMarker = null;
    var destMarker = null;
    var routeLine = null;

    const btnPinOrigin = document.getElementById('btn_pin_origin');
    const btnPinDest = document.getElementById('btn_pin_dest');

    btnPinOrigin.addEventListener('click', function() {
        currentPinMode = 'origin';
        btnPinOrigin.classList.add('active');
        btnPinDest.classList.remove('active');
    });

    btnPinDest.addEventListener('click', function() {
        currentPinMode = 'destination';
        btnPinDest.classList.add('active');
        btnPinOrigin.classList.remove('active');
    });

    // 3. Handle clicks on the map
    map.on('click', function(e) {
        var lat = e.latlng.lat;
        var lng = e.latlng.lng;

        if (currentPinMode === 'origin') {
            // Place/Update Origin Marker
            if (originMarker) {
                originMarker.setLatLng(e.latlng);
            } else {
                // Green icon for pickup origin
                var greenIcon = new L.Icon({
                    iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-green.png',
                    shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
                    iconSize: [25, 41],
                    iconAnchor: [12, 41],
                    popupAnchor: [1, -34],
                    shadowSize: [41, 41]
                });
                originMarker = L.marker(e.latlng, {icon: greenIcon}).addTo(map);
            }
            // Update inputs
            document.getElementById('origin_lat').value = lat;
            document.getElementById('origin_lng').value = lng;
            
            // Reverse Geocode pickup address
            reverseGeocode(lat, lng, 'origin');

        } else {
            // Place/Update Destination Marker
            if (destMarker) {
                destMarker.setLatLng(e.latlng);
            } else {
                // Red icon for dropoff destination
                var redIcon = new L.Icon({
                    iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png',
                    shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
                    iconSize: [25, 41],
                    iconAnchor: [12, 41],
                    popupAnchor: [1, -34],
                    shadowSize: [41, 41]
                });
                destMarker = L.marker(e.latlng, {icon: redIcon}).addTo(map);
            }
            // Update inputs
            document.getElementById('destination_lat').value = lat;
            document.getElementById('destination_lng').value = lng;
            
            // Reverse Geocode dropoff address
            reverseGeocode(lat, lng, 'destination');
        }

        // Draw connecting polyline path if both markers are placed
        updateRouteLine();
    });

    function updateRouteLine() {
        if (originMarker && destMarker) {
            var latlngs = [
                originMarker.getLatLng(),
                destMarker.getLatLng()
            ];
            
            if (routeLine) {
                routeLine.setLatLngs(latlngs);
            } else {
                routeLine = L.polyline(latlngs, {
                    color: '#6366f1',
                    weight: 3,
                    dashArray: '5, 10'
                }).addTo(map);
            }
            // Fit bounds to show both pins
            var group = new L.featureGroup([originMarker, destMarker]);
            map.fitBounds(group.getBounds(), {padding: [50, 50]});
        }
    }

    // 4. Reverse Geocoding helper using OpenStreetMap Nominatim API
    function reverseGeocode(lat, lng, fieldId) {
        var inputField = document.getElementById(fieldId);
        inputField.value = "Fetching address...";
        
        var fetchUrl = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=0`;
        
        fetch(fetchUrl, {
            headers: {
                'User-Agent': 'SakayPH_Chauffeur_Marketplace_App'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.display_name) {
                // Shorten address (e.g. get first 3 parts of address for cleanliness)
                var addressParts = data.display_name.split(',');
                var shortAddress = addressParts.slice(0, 3).join(',').trim();
                inputField.value = shortAddress;
            } else {
                inputField.value = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
            }
        })
        .catch(err => {
            inputField.value = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
            console.error("Geocoding failed: ", err);
        });
    }

    // 5. Autocomplete Forward Geocoding using Nominatim API
    function setupAutocomplete(inputId, suggsId, type) {
        const input = document.getElementById(inputId);
        const suggs = document.getElementById(suggsId);
        let timeout = null;

        input.addEventListener('input', function() {
            clearTimeout(timeout);
            const query = this.value.trim();
            if (query.length < 3) {
                suggs.style.display = 'none';
                return;
            }

            timeout = setTimeout(() => {
                fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&countrycodes=ph`)
                    .then(res => res.json())
                    .then(data => {
                        suggs.innerHTML = '';
                        if (data.length > 0) {
                            data.slice(0, 5).forEach(item => {
                                const li = document.createElement('li');
                                li.className = 'list-group-item suggestion-item';
                                
                                var addressParts = item.display_name.split(',');
                                var shortAddress = addressParts.slice(0, 3).join(',').trim();
                                
                                li.innerText = shortAddress;
                                li.addEventListener('click', () => {
                                    input.value = shortAddress;
                                    suggs.style.display = 'none';
                                    
                                    // Save lat lng
                                    var lat = parseFloat(item.lat);
                                    var lon = parseFloat(item.lon);
                                    document.getElementById(type + '_lat').value = lat;
                                    document.getElementById(type + '_lng').value = lon;

                                    // Place Marker
                                    var latlng = L.latLng(lat, lon);
                                    if (type === 'origin') {
                                        if (originMarker) originMarker.setLatLng(latlng);
                                        else {
                                            var greenIcon = new L.Icon({
                                                iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-green.png',
                                                shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
                                                iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41]
                                            });
                                            originMarker = L.marker(latlng, {icon: greenIcon}).addTo(map);
                                        }
                                    } else {
                                        if (destMarker) destMarker.setLatLng(latlng);
                                        else {
                                            var redIcon = new L.Icon({
                                                iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png',
                                                shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
                                                iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41]
                                            });
                                            destMarker = L.marker(latlng, {icon: redIcon}).addTo(map);
                                        }
                                    }
                                    
                                    map.setView(latlng, 14);
                                    updateRouteLine();
                                });
                                suggs.appendChild(li);
                            });
                            suggs.style.display = 'block';
                        }
                    });
            }, 500);
        });

        document.addEventListener('click', (e) => {
            if (e.target !== input && e.target !== suggs) {
                suggs.style.display = 'none';
            }
        });
    }

    setupAutocomplete('originInput', 'originSuggestions', 'origin');
    setupAutocomplete('destInput', 'destSuggestions', 'destination');
});
</script>

<?php
include_once __DIR__ . '/../includes/footer.php';
?>
