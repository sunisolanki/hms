<?php
/*
|--------------------------------------------------------------------------
| WebSplend HMS - Access Unavailable
|--------------------------------------------------------------------------
*/
$displayTenantId = !empty($tenantId)
    ? e($tenantId)
    : 'Not Available';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Access Unavailable - WebSplend HMS</title>
    <link
        rel="icon"
        type="image/png"
        href="/assets/img/hms_logo.png"
    >
    <style>
        /* =========================================================
           RESET
        ========================================================= */
        * {
            box-sizing: border-box;
        }
        html,
        body {
            width: 100%;
            min-height: 100%;
            margin: 0;
            padding: 0;
        }
        body {
            font-family:
                Inter,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                Roboto,
                Arial,
                sans-serif;
            color: #172033;
            background:
                #eef5f7;
            overflow-x: hidden;
        }
        /* =========================================================
           BACKGROUND
        ========================================================= */
        .background {
            position: fixed;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: 0;
            background:
                radial-gradient(
                    circle at 10% 15%,
                    rgba(35, 196, 174, 0.20),
                    transparent 28%
                ),
                radial-gradient(
                    circle at 90% 10%,
                    rgba(47, 112, 220, 0.18),
                    transparent 30%
                ),
                radial-gradient(
                    circle at 85% 90%,
                    rgba(101, 75, 190, 0.14),
                    transparent 28%
                ),
                linear-gradient(
                    135deg,
                    #edf7f6 0%,
                    #f5f8fc 48%,
                    #edf3fa 100%
                );
        }
        .background::before {
            content: "";
            position: absolute;
            width: 520px;
            height: 520px;
            top: -220px;
            left: -160px;
            border-radius: 50%;
            background:
                linear-gradient(
                    135deg,
                    rgba(20, 184, 166, 0.18),
                    rgba(37, 99, 235, 0.06)
                );
            filter: blur(2px);
        }
        .background::after {
            content: "";
            position: absolute;
            width: 460px;
            height: 460px;
            right: -170px;
            bottom: -190px;
            border-radius: 50%;
            background:
                linear-gradient(
                    135deg,
                    rgba(99, 102, 241, 0.15),
                    rgba(20, 184, 166, 0.08)
                );
    }
        /* Decorative grid */
        .grid-pattern {
            position: absolute;
            inset: 0;
            opacity: 0.25;
            background-image:
                linear-gradient(
                    rgba(100, 116, 139, 0.06) 1px,
                    transparent 1px
                ),
                linear-gradient(
                    90deg,
                    rgba(100, 116, 139, 0.06) 1px,
                    transparent 1px
                );
            background-size: 42px 42px;
            mask-image:
                linear-gradient(
                    to bottom,
                    black,
                    transparent 75%
                );
        }


        /* =========================================================
           PAGE
        ========================================================= */

        .page {
            position: relative;

            z-index: 1;

            width: 100%;

            min-height: 100vh;

            padding:
                22px 25px
                18px;

            display: flex;

            flex-direction: column;

            justify-content: center;
        }


        .container {
            width: 100%;

            max-width: 1080px;

            margin: 0 auto;
        }


        /* =========================================================
           BRAND
        ========================================================= */

        .brand {
            display: flex;

            align-items: center;

            justify-content: center;

            margin-bottom: 17px;
        }


        .brand-logo {
            display: block;

            max-width: 150px;
            max-height: 55px;

            object-fit: contain;
        }


        .brand-fallback {
            display: flex;

            align-items: center;

            gap: 10px;
        }


        .brand-mark {
            width: 42px;
            height: 42px;

            display: flex;

            align-items: center;
            justify-content: center;

            border-radius: 12px;

            color: white;

            font-size: 16px;

            font-weight: 800;

            background:
                linear-gradient(
                    135deg,
                    #0f766e,
                    #2563eb
                );

            box-shadow:
                0 8px 20px
                rgba(37, 99, 235, 0.20);
        }


        .brand-name {
            font-size: 20px;

            font-weight: 750;

            color: #172033;
        }


        /* =========================================================
           MAIN CARD
        ========================================================= */

        .main-card {
            width: 100%;

            display: grid;

            grid-template-columns:
                0.92fr
                1.08fr;

            background:
                rgba(255, 255, 255, 0.88);

            border:
                1px solid
                rgba(255, 255, 255, 0.90);

            border-radius: 26px;

            overflow: hidden;

            box-shadow:
                0 30px 80px
                rgba(15, 23, 42, 0.13),
                0 8px 25px
                rgba(15, 23, 42, 0.06);

            backdrop-filter:
                blur(18px);

            -webkit-backdrop-filter:
                blur(18px);
        }


        /* =========================================================
           LEFT PANEL
        ========================================================= */

        .left-panel {
            position: relative;

            padding:
                38px 42px
                34px;

            background:
                linear-gradient(
                    145deg,
                    #102a43 0%,
                    #123c56 48%,
                    #145c65 100%
                );

            color: #ffffff;

            display: flex;

            flex-direction: column;

            justify-content: center;

            overflow: hidden;
        }


        .left-panel::before {
            content: "";

            position: absolute;

            width: 300px;
            height: 300px;

            top: -150px;
            right: -130px;

            border-radius: 50%;

            background:
                rgba(
                    45,
                    212,
                    191,
                    0.14
                );
        }


        .left-panel::after {
            content: "";

            position: absolute;

            width: 230px;
            height: 230px;

            bottom: -120px;
            left: -100px;

            border-radius: 50%;

            background:
                rgba(
                    59,
                    130,
                    246,
                    0.15
                );
        }


        .left-content {
            position: relative;

            z-index: 2;
        }


        /* =========================================================
           STATUS BADGE
        ========================================================= */

        .status-badge {
            display: inline-flex;

            align-items: center;

            gap: 7px;

            padding:
                6px 11px;

            margin-bottom: 20px;

            border-radius: 999px;

            background:
                rgba(
                    255,
                    255,
                    255,
                    0.10
                );

            border:
                1px solid
                rgba(
                    255,
                    255,
                    255,
                    0.14
                );

            color:
                rgba(
                    255,
                    255,
                    255,
                    0.85
                );

            font-size: 11px;

            font-weight: 600;

            letter-spacing: 0.04em;

            text-transform: uppercase;
        }


        .status-dot {
            width: 7px;
            height: 7px;

            border-radius: 50%;

            background: #fb7185;

            box-shadow:
                0 0 0 4px
                rgba(251, 113, 133, 0.12);
        }


        /* =========================================================
           LOCK
        ========================================================= */

        .lock-wrapper {
            width: 70px;
            height: 70px;

            margin-bottom: 20px;

            border-radius: 20px;

            display: flex;

            align-items: center;
            justify-content: center;

            background:
                rgba(
                    255,
                    255,
                    255,
                    0.10
                );

            border:
                1px solid
                rgba(
                    255,
                    255,
                    255,
                    0.12
                );
        }


        .lock-icon {
            position: relative;

            width: 38px;
            height: 38px;
        }


        .lock-body {
            position: absolute;

            left: 5px;
            bottom: 1px;

            width: 28px;
            height: 25px;

            background: #fb7185;

            border-radius: 6px;
        }


        .lock-shackle {
            position: absolute;

            left: 10px;
            top: 0;

            width: 18px;
            height: 23px;

            border:
                5px solid
                #fb7185;

            border-bottom: 0;

            border-radius:
                12px 12px 0 0;
        }


        .lock-keyhole {
            position: absolute;

            left: 16px;
            top: 15px;

            width: 6px;
            height: 11px;

            background: #ffffff;

            border-radius: 4px;

            z-index: 2;
        }


        /* =========================================================
           LEFT CONTENT
        ========================================================= */

        .access-title {
            margin: 0;

            font-size: 30px;

            line-height: 1.15;

            letter-spacing: -0.5px;

            color: #ffffff;
        }


        .message {
            max-width: 455px;

            margin:
                13px 0 0;

            font-size: 14px;

            line-height: 1.65;

            color:
                rgba(
                    255,
                    255,
                    255,
                    0.72
                );
        }


        /* =========================================================
           TENANT
        ========================================================= */

        .tenant-box {
            margin-top: 21px;

            padding:
                13px 15px;

            border-radius: 12px;

            background:
                rgba(
                    255,
                    255,
                    255,
                    0.08
                );

            border:
                1px solid
                rgba(
                    255,
                    255,
                    255,
                    0.12
                );
        }


        .tenant-label {
            display: block;

            margin-bottom: 5px;

            font-size: 9px;

            font-weight: 600;

            letter-spacing: 0.12em;

            text-transform: uppercase;

            color:
                rgba(
                    255,
                    255,
                    255,
                    0.50
                );
        }


        .tenant-value {
            display: block;

            font-size: 15px;

            font-weight: 700;

            color: #ffffff;

            word-break: break-word;
        }


        /* =========================================================
           NOTICE
        ========================================================= */

        .notice {
            margin-top: 12px;

            padding:
                10px 13px;

            border-radius: 10px;

            background:
                rgba(
                    251,
                    113,
                    133,
                    0.09
                );

            border:
                1px solid
                rgba(
                    251,
                    113,
                    133,
                    0.18
                );

            color:
                rgba(
                    255,
                    255,
                    255,
                    0.75
                );

            font-size: 11px;

            line-height: 1.5;
        }


        /* =========================================================
           ACTIONS
        ========================================================= */

        .actions {
            display: grid;

            grid-template-columns:
                1fr 1fr;

            gap: 9px;

            margin-top: 19px;
        }


        .btn {
            min-height: 42px;

            padding:
                0 13px;

            display: flex;

            align-items: center;

            justify-content: center;

            border-radius: 9px;

            font-size: 12px;

            font-weight: 650;

            text-decoration: none;

            transition:
                0.2s ease;
        }


        .btn-primary {
            color: #102a43;

            background: #ffffff;
        }


        .btn-primary:hover {
            transform:
                translateY(-1px);

            background: #f0fdfa;
        }


        .btn-secondary {
            color: #ffffff;

            background:
                rgba(
                    255,
                    255,
                    255,
                    0.07
                );

            border:
                1px solid
                rgba(
                    255,
                    255,
                    255,
                    0.20
                );
        }


        .btn-secondary:hover {
            background:
                rgba(
                    255,
                    255,
                    255,
                    0.13
                );
        }


        /* =========================================================
           RIGHT PANEL
        ========================================================= */

        .right-panel {
            background:
                rgba(
                    255,
                    255,
                    255,
                    0.94
                );

            display: flex;

            flex-direction: column;
        }


        /* =========================================================
           SUPPORT HEADER
        ========================================================= */

        .support-header {
            padding:
                25px 27px
                20px;

            background:
                linear-gradient(
                    135deg,
                    #f0fdfa 0%,
                    #f8fbff 100%
                );

            border-bottom:
                1px solid
                #e8eef3;
        }


        .support-heading {
            display: flex;

            align-items: center;

            gap: 14px;
        }


        .support-icon {
            width: 55px;
            height: 55px;

            min-width: 55px;

            display: flex;

            align-items: center;
            justify-content: center;

            border-radius: 16px;

            background:
                linear-gradient(
                    135deg,
                    #0f766e,
                    #2563eb
                );

            box-shadow:
                0 9px 20px
                rgba(
                    37,
                    99,
                    235,
                    0.17
                );
        }


        .support-icon svg {
            width: 31px;
            height: 31px;

            fill: none;

            stroke: #ffffff;

            stroke-width: 2.4;

            stroke-linecap: round;
            stroke-linejoin: round;
        }


        .support-title {
            margin: 0;

            font-size: 20px;

            color: #172033;
        }


        .support-subtitle {
            margin:
                4px 0 0;

            font-size: 12px;

            color: #718096;
        }


        /* =========================================================
           CONTACT AREA
        ========================================================= */

        .contact-area {
            padding:
                20px 22px
                21px;
        }


        .contact-intro {
            margin:
                0 0 13px;

            font-size: 11px;

            font-weight: 600;

            color: #94a3b8;

            letter-spacing: 0.07em;

            text-transform: uppercase;
        }


        /* =========================================================
           CONTACT GRID
        ========================================================= */

        .contact-grid {
            display: grid;

            grid-template-columns:
                1fr 1fr;

            gap: 11px;
        }


        /* =========================================================
           CONTACT CARD
        ========================================================= */

        .contact-item {
            position: relative;

            min-height: 91px;

            padding:
                13px;

            display: flex;

            align-items: center;

            gap: 11px;

            text-decoration: none;

            background: #ffffff;

            border:
                1px solid
                #e4e9ee;

            border-radius: 13px;

            overflow: hidden;

            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease,
                border-color 0.2s ease;
        }


        .contact-item::after {
            content: "";

            position: absolute;

            left: 0;
            bottom: 0;

            width: 100%;
            height: 3px;

            opacity: 0;

            transition:
                opacity 0.2s ease;
        }


        .contact-item:hover {
            transform:
                translateY(-2px);

            border-color:
                #d5dce3;

            box-shadow:
                0 10px 25px
                rgba(
                    15,
                    23,
                    42,
                    0.08
                );
        }


        .contact-item:hover::after {
            opacity: 1;
        }


        .phone-card::after {
            background: #2563eb;
        }


        .whatsapp-card::after {
            background: #16a34a;
        }


        .email-card::after {
            background: #f59e0b;
        }


        .website-card::after {
            background: #7c3aed;
        }


        /* =========================================================
           CONTACT ICON
        ========================================================= */

        .contact-icon {
            width: 47px;
            height: 47px;

            min-width: 47px;

            display: flex;

            align-items: center;
            justify-content: center;

            border-radius: 12px;
        }


        .contact-icon svg {
            width: 25px;
            height: 25px;

            fill: none;

            stroke: #ffffff;

            stroke-width: 2;

            stroke-linecap: round;
            stroke-linejoin: round;
        }


        .phone-icon {
            background:
                linear-gradient(
                    135deg,
                    #2563eb,
                    #3b82f6
                );
        }


        .whatsapp-icon {
            background:
                linear-gradient(
                    135deg,
                    #16a34a,
                    #22c55e
                );
        }


        .email-icon {
            background:
                linear-gradient(
                    135deg,
                    #f59e0b,
                    #fbbf24
                );
        }


        .website-icon {
            background:
                linear-gradient(
                    135deg,
                    #7c3aed,
                    #8b5cf6
                );
        }


        /* =========================================================
           CONTACT TEXT
        ========================================================= */

        .contact-info {
            min-width: 0;
        }


        .contact-label {
            display: block;

            margin-bottom: 4px;

            font-size: 11px;

            color: #94a3b8;
        }


        .contact-value {
            display: block;

            font-size: 13px;

            line-height: 1.3;

            font-weight: 700;

            color: #172033;

            word-break: break-word;
        }


        /* =========================================================
           SUPPORT NOTE
        ========================================================= */

        .support-note {
            display: flex;

            align-items: center;

            gap: 8px;

            margin-top: 13px;

            padding:
                10px 12px;

            border-radius: 9px;

            background: #f8fafc;

            color: #64748b;

            font-size: 10px;

            line-height: 1.4;
        }


        .support-note-icon {
            width: 22px;
            height: 22px;

            min-width: 22px;

            display: flex;

            align-items: center;
            justify-content: center;

            border-radius: 7px;

            color: #0f766e;

            background: #ccfbf1;

            font-size: 11px;

            font-weight: 800;
        }


        /* =========================================================
           FOOTER
        ========================================================= */

        .footer {
            margin-top: 12px;

            text-align: center;

            font-size: 10px;

            color: #94a3b8;
        }


        /* =========================================================
           DESKTOP HEIGHT OPTIMIZATION
        ========================================================= */

        @media (
            min-width: 850px
        ) and (
            min-height: 650px
        ) {

            .main-card {
                min-height: 500px;
            }

        }


        /* =========================================================
           TABLET
        ========================================================= */

        @media (
            min-width: 601px
        ) and (
            max-width: 900px
        ) {

            .page {
                padding:
                    20px 15px;
            }


            .main-card {
                grid-template-columns:
                    1fr 1fr;
            }


            .left-panel {
                padding:
                    30px 25px;
            }


            .contact-grid {
                grid-template-columns:
                    1fr;
            }


            .contact-item {
                min-height: 74px;
            }

        }


        /* =========================================================
           MOBILE
        ========================================================= */

        @media (max-width: 600px) {

            html,
            body {
                height: auto;
            }


            body {
                overflow-y: auto;
            }


            .page {
                min-height: 100vh;

                padding:
                    18px 10px;
            }


            .brand {
                margin-bottom: 13px;
            }


            .brand-logo {
                max-width: 135px;
            }


            .main-card {
                grid-template-columns:
                    1fr;

                border-radius: 20px;
            }


            .left-panel {
                padding:
                    29px 21px
                    25px;
            }


            .lock-wrapper {
                width: 62px;
                height: 62px;

                border-radius: 17px;

                margin-bottom: 16px;
            }


            .access-title {
                font-size: 26px;
            }


            .message {
                font-size: 13px;
            }


            .actions {
                grid-template-columns:
                    1fr;
            }


            .right-panel {
                border-top:
                    1px solid
                    #e8eef3;
            }


            .support-header {
                padding:
                    20px;
            }


            .contact-area {
                padding:
                    17px 15px
                    18px;
            }


            .contact-grid {
                grid-template-columns:
                    1fr;
            }


            .contact-item {
                min-height: 76px;
            }


            .footer {
                margin-bottom: 5px;
            }

        }


        /* =========================================================
           SMALL MOBILE
        ========================================================= */

        @media (max-width: 380px) {

            .left-panel {
                padding:
                    25px 17px;
            }


            .access-title {
                font-size: 23px;
            }


            .support-heading {
                gap: 11px;
            }


            .support-icon {
                width: 49px;
                height: 49px;

                min-width: 49px;
            }


            .support-title {
                font-size: 18px;
            }


            .contact-area {
                padding:
                    15px 12px;
            }


            .contact-icon {
                width: 44px;
                height: 44px;

                min-width: 44px;
            }


            .contact-value {
                font-size: 12px;
            }

        }

    </style>

</head>


<body>


<!-- =============================================================
     BACKGROUND
============================================================== -->

<div class="background">

    <div class="grid-pattern"></div>

</div>


<div class="page">


    <div class="container">


        <!-- =====================================================
             BRAND
        ====================================================== -->

        <div class="brand">

            <?php

            $logoPath =
                $_SERVER['DOCUMENT_ROOT']
                . '/assets/img/hms_logo.png';

            ?>

            <?php if (file_exists($logoPath)): ?>

                <img
                    class="brand-logo"
                    src="/assets/img/hms_logo.png"
                    alt="WebSplend HMS"
                >

            <?php else: ?>

                <div class="brand-fallback">

                    <div class="brand-mark">
                        WS
                    </div>

                    <div class="brand-name">
                        WebSplend HMS
                    </div>

                </div>

            <?php endif; ?>

        </div>


        <!-- =====================================================
             MAIN CARD
        ====================================================== -->

        <main class="main-card">


            <!-- =================================================
                 LEFT: ACCESS
            ================================================== -->

            <section class="left-panel">


                <div class="left-content">


                    <!-- Status -->

                    <div class="status-badge">

                        <span class="status-dot"></span>

                        Account Access Restricted

                    </div>


                    <!-- Lock -->

                    <div class="lock-wrapper">

                        <div class="lock-icon">

                            <div
                                class="lock-shackle"
                            ></div>

                            <div
                                class="lock-body"
                            ></div>

                            <div
                                class="lock-keyhole"
                            ></div>

                        </div>

                    </div>


                    <!-- Heading -->

                    <h1 class="access-title">

                        Access Unavailable

                    </h1>


                    <!-- Message -->

                    <p class="message">

                        Your HMS account is currently unavailable.
                        Please contact your HMS administrator or
                        WebSplend support for assistance.

                    </p>


                    <!-- Tenant -->

                    <div class="tenant-box">

                        <span class="tenant-label">
                            Tenant ID
                        </span>

                        <span class="tenant-value">
                            <?= $displayTenantId ?>
                        </span>

                    </div>


                    <!-- Notice -->

                    <div class="notice">

                        Access to this HMS account has been
                        temporarily blocked. Please contact
                        support to restore access.

                    </div>


                    <!-- Actions -->

                    <div class="actions">


                        <a
                            href="mailto:info@websplend.com"
                            class="btn btn-primary"
                        >
                            Contact Support
                        </a>


                        <a
                            href="/login/login.php"
                            class="btn btn-secondary"
                        >
                            Return to Login
                        </a>


                    </div>


                </div>


            </section>


            <!-- =================================================
                 RIGHT: SUPPORT
            ================================================== -->

            <section class="right-panel">


                <!-- Support Header -->

                <div class="support-header">

                    <div class="support-heading">


                        <div class="support-icon">

                            <svg viewBox="0 0 48 48">

                                <path
                                    d="
                                    M8 25
                                    a16 16 0 0 1 32 0
                                    "
                                />

                                <rect
                                    x="5"
                                    y="23"
                                    width="8"
                                    height="14"
                                    rx="4"
                                />

                                <rect
                                    x="35"
                                    y="23"
                                    width="8"
                                    height="14"
                                    rx="4"
                                />

                                <path
                                    d="
                                    M35 36
                                    c0 4-3 6-8 6h-4
                                    "
                                />

                                <circle
                                    cx="21"
                                    cy="42"
                                    r="2"
                                    fill="#ffffff"
                                    stroke="none"
                                />

                            </svg>

                        </div>


                        <div>

                            <h2 class="support-title">
                                Contact Support
                            </h2>

                            <p class="support-subtitle">
                                We're here to help you restore access
                            </p>

                        </div>


                    </div>

                </div>


                <!-- Contact Area -->

                <div class="contact-area">


                    <p class="contact-intro">
                        Choose a support channel
                    </p>


                    <div class="contact-grid">


                        <!-- =====================================
                             PHONE
                        ====================================== -->

                        <a
                            href="tel:+918580501114"
                            class="contact-item phone-card"
                        >

                            <div
                                class="contact-icon phone-icon"
                            >

                                <svg viewBox="0 0 24 24">

                                    <path
                                        d="
                                        M22 16.92v3
                                        a2 2 0 0 1-2.18 2
                                        19.79 19.79 0 0 1-8.63-3.07
                                        19.5 19.5 0 0 1-6-6
                                        19.79 19.79 0 0 1-3.07-8.67
                                        A2 2 0 0 1 4.11 2
                                        h3
                                        a2 2 0 0 1 2 1.72
                                        12.84 12.84 0 0 0 .7 2.81
                                        2 2 0 0 1-.45 2.11
                                        L8.09 9.91
                                        a16 16 0 0 0 6 6
                                        l1.27-1.27
                                        a2 2 0 0 1 2.11-.45
                                        12.84 12.84 0 0 0 2.81.7
                                        A2 2 0 0 1 22 16.92
                                        "
                                    />

                                </svg>

                            </div>


                            <div class="contact-info">

                                <span class="contact-label">
                                    Phone Number
                                </span>

                                <span class="contact-value">
                                    +91 85805 01114
                                </span>

                            </div>

                        </a>


                        <!-- =====================================
                             WHATSAPP
                        ====================================== -->

                        <a
                            href="https://wa.me/918580501114"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="contact-item whatsapp-card"
                        >

                            <div
                                class="contact-icon whatsapp-icon"
                            >

                                <svg viewBox="0 0 24 24">

                                    <path
                                        d="
                                        M20.52 3.48
                                        A11.9 11.9 0 0 0 12.04 0
                                        C5.45 0 .09 5.36.09 11.95
                                        c0 2.11.55 4.17 1.6 5.98
                                        L0 24
                                        l6.22-1.63
                                        a11.94 11.94 0 0 0 5.82 1.52
                                        h.01
                                        c6.59 0 11.95-5.36 11.95-11.95
                                        0-3.19-1.24-6.19-3.48-8.46z
                                        "
                                    />

                                    <path
                                        d="
                                        M8.8 7.1
                                        c-.25-.55-.52-.56-.76-.57
                                        h-.65
                                        c-.23 0-.6.09-.91.43
                                        -.31.34-1.18 1.15-1.18 2.8
                                        0 1.65 1.21 3.24 1.38 3.46
                                        .17.22 2.34 3.75 5.78 5.1
                                        2.86 1.12 3.44.9 4.06.84
                                        .62-.06 2-.82 2.28-1.62
                                        .28-.8.28-1.49.2-1.63
                                        -.08-.14-.31-.22-.65-.39
                                        -.34-.17-2-.99-2.31-1.1
                                        -.31-.11-.53-.17-.76.17
                                        -.23.34-.87 1.1-1.06 1.33
                                        -.2.22-.39.25-.73.08
                                        -.34-.17-1.45-.53-2.76-1.7
                                        -1.02-.91-1.7-2.04-1.9-2.38
                                        -.2-.34-.02-.52.15-.69
                                        .15-.15.34-.39.51-.59
                                        .17-.2.23-.34.34-.57
                                        .11-.23.06-.43-.03-.6z
                                        "
                                    />

                                </svg>

                            </div>


                            <div class="contact-info">

                                <span class="contact-label">
                                    WhatsApp
                                </span>

                                <span class="contact-value">
                                    Chat on WhatsApp
                                </span>

                            </div>

                        </a>


                        <!-- =====================================
                             EMAIL
                        ====================================== -->

                        <a
                            href="mailto:info@websplend.com"
                            class="contact-item email-card"
                        >

                            <div
                                class="contact-icon email-icon"
                            >

                                <svg viewBox="0 0 24 24">

                                    <rect
                                        x="2"
                                        y="4"
                                        width="20"
                                        height="16"
                                        rx="2"
                                    />

                                    <path
                                        d="M2 6l10 7L22 6"
                                    />

                                </svg>

                            </div>


                            <div class="contact-info">

                                <span class="contact-label">
                                    Email ID
                                </span>

                                <span class="contact-value">
                                    info@websplend.com
                                </span>

                            </div>

                        </a>


                        <!-- =====================================
                             WEBSITE
                        ====================================== -->
                        <a
                            href="https://www.websplend.com"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="contact-item website-card"
                        >
                            <div
                                class="contact-icon website-icon"
                            >
                                <svg viewBox="0 0 24 24">
                                    <circle
                                        cx="12"
                                        cy="12"
                                        r="9"
                                    />
                                    <path
                                        d="M3 12h18"
                                    />
                                    <path
                                        d="
                                        M12 3
                                        c2.2 2.4 3.3 5.4 3.3 9
                                        S14.2 18.6 12 21
                                        "
                                    />
                                    <path
                                        d="
                                        M12 3
                                        c-2.2 2.4-3.3 5.4-3.3 9
                                        S9.8 18.6 12 21
                                    "
                                    />
                                </svg>
                            </div>
                            <div class="contact-info">
                                <span class="contact-label">
                                    Website
                                </span>
                                <span class="contact-value">
                                    www.websplend.com
                                </span>
                            </div>
                        </a>
                    </div>
                    <!-- Support note -->
                    <div class="support-note">
                        <span class="support-note-icon">
                            ✓
                        </span>
                        <span>
                            Our support team can help you restore your HMS account access.
                        </span>
                    </div>
                </div>
            </section>
        </main>
        <!-- =====================================================
             FOOTER
        ====================================================== -->
        <div class="footer">
            © <?= date('Y') ?> WebSplend· Hospital Management System
        </div>
    </div>
</div>
</body>
</html>