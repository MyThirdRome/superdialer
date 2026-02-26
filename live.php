<?php
require_once 'includes/header.php';
?>

<h2>Live Calls (<span id="total_calls_count">0</span>)</h2>

<div style="margin-bottom: 10px;">
    Refresh Interval: 
    <select id="refresh_interval">
        <option value="1000">1s</option>
        <option value="2000">2s</option>
        <option value="5000" selected>5s</option>
        <option value="10000">10s</option>
    </select>
</div>

<table>
    <thead>
        <tr>
            <th>Campaign</th>
            <th>Trunk</th>
            <th>Caller ID</th> <!-- Added Column -->
            <th>Number</th>
            <th>Status</th>
            <th>Duration (s)</th>
            <th>Called At</th>
        </tr>
    </thead>
    <tbody id="live_calls">
        <!-- Content via AJAX -->
    </tbody>
</table>

<script>
    function loadLiveCalls() {
        $.getJSON('api.php?action=live', function(data) {
            var html = '';
            var calls = data.calls;
            var total = data.total_calls;
            
            $('#total_calls_count').text(total);

            if (calls.length === 0) {
                html = '<tr><td colspan="7">No active calls</td></tr>';
            } else {
                $.each(calls, function(i, call) {
                    html += '<tr>';
                    html += '<td>' + call.campaign_name + '</td>';
                    html += '<td>' + call.trunk_name + '</td>';
                    html += '<td>' + call.caller_id + '</td>'; // Display Caller ID
                    html += '<td>' + call.number + '</td>';
                    html += '<td>' + call.status.toUpperCase() + '</td>';
                    html += '<td>' + call.duration + '</td>';
                    html += '<td>' + call.called_at + '</td>';
                    html += '</tr>';
                });
            }
            $('#live_calls').html(html);
        });
    }

    var intervalId;
    function setRefresh() {
        clearInterval(intervalId);
        var ms = $('#refresh_interval').val();
        intervalId = setInterval(loadLiveCalls, ms);
    }

    $(document).ready(function() {
        loadLiveCalls();
        setRefresh();
        $('#refresh_interval').change(setRefresh);
    });
</script>

<?php require_once 'includes/footer.php'; ?>
