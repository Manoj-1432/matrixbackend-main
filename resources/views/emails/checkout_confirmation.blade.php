<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Booking Confirmation</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.5;">
<h2>Thank you for your booking</h2>

<p>Hi {{ $customer?->name ?? 'Customer' }},</p>
<p>Your payment has been confirmed. Here are your booking details.</p>

<h3>Order Details</h3>
<ul>
    <li>Order ID: {{ $order->id }}</li>
    <li>Status: {{ ucfirst((string) $order->status) }}</li>
    <li>Payment Status: {{ ucfirst((string) $order->payment_status) }}</li>
    <li>Total: {{ number_format((float) $order->amount, 2) }}</li>
    <li>Service: {{ $order->service_type }}</li>
    <li>Tyre: {{ $order->tyre_brand }} {{ $order->tyre_model }} ({{ $order->tyre_size }}) x {{ $order->tyre_quantity }}</li>
    @if(!empty($order->vehicle_registration))
        <li>Vehicle Registration: {{ $order->vehicle_registration }}</li>
    @endif
    @if(!empty($order->vehicle_make) || !empty($order->vehicle_model))
        <li>Vehicle: {{ trim(($order->vehicle_make ?? '') . ' ' . ($order->vehicle_model ?? '')) }}</li>
    @endif
</ul>

<h3>Booking Information</h3>
<ul>
    <li>Fitting Date: {{ $order->fitting_date?->format('Y-m-d') ?? 'N/A' }}</li>
    <li>Slot: {{ $bookingSlot?->day ? ucfirst((string) $bookingSlot->day) : 'N/A' }}
        @if($bookingSlot)
            ({{ $bookingSlot->start_time }} - {{ $bookingSlot->end_time }})
        @endif
    </li>
    @if(!empty($customer?->address))
        <li>Address: {{ $customer->address }}, {{ $customer->city }}, {{ $customer->postcode }}</li>
    @endif
</ul>

<h3>Login Details</h3>
<ul>
    <li>Email: {{ $customer?->email }}</li>
    @if(!empty($loginUrl))
        <li>Login: <a href="{{ $loginUrl }}">{{ $loginUrl }}</a></li>
    @endif
    @if($plainPassword)
        <li>Temporary Password: {{ $plainPassword }}</li>
    @endif
</ul>

@if($plainPassword)
    <p>Your account was created during checkout. Please log in and change your password as soon as possible.</p>
@elseif(!empty($forgotPasswordUrl))
    <p>If you forgot your password, reset it here: <a href="{{ $forgotPasswordUrl }}">{{ $forgotPasswordUrl }}</a></p>
@endif

<p>Need help? Reply to this email and our team will assist you.</p>
</body>
</html>
