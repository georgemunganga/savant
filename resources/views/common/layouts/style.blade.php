<link href="{{ asset('assets/libs/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
<link href="{{ asset('assets/libs/jquery-ui/jquery-ui.min.css') }}" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('assets/css/animate.min.css') }}">

<!-- Font CSS -->
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.cdnfonts.com/css/avenir" rel="stylesheet">
<style>
    @import url('https://fonts.cdnfonts.com/css/avenir');
</style>

<link rel="stylesheet" href="{{ asset('assets/libs/owl-carousel/owl.carousel.min.css') }}">
<link rel="stylesheet" href="{{ asset('assets/libs/owl-carousel/owl.theme.default.min.css') }}">

<link rel="stylesheet" href="{{ asset('assets/libs/venobox/venobox.min.css') }}">
<link href="{{ asset('assets/css/icons.min.css') }}" rel="stylesheet">

<!-- Dropzone css -->
<link href="{{ asset('assets/libs/dropzone/dropzone.css') }}" rel="stylesheet">
<style>
    :root {
        @if (getOption('website_color_mode', 0) == ACTIVE)
            --primary-color: {{ getOption('website_primary_color', '#d97a36') }};
            --secondary-color: {{ getOption('website_secondary_color', '#8253FB') }};
            --button-primary-color: {{ getOption('button_primary_color', '#d97a36') }};
            --button-hover-color: {{ getOption('button_hover_color', '#b23423') }};
        @else
            --primary-color: #b23423;
            --secondary-color: #8253FB;
            --button-primary-color: #d97a36;
            --button-hover-color: #b23423;
        @endif
    }
</style>
<link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
<link href="{{ asset('assets/css/extra-style.css') }}" rel="stylesheet">

<!-- RTL Style Start -->
@if (selectedLanguage()->rtl == 1)
    <link href="{{ asset('assets/css/rtl-style.css') }}" rel="stylesheet">
@endif
<!-- RTL Style End -->

<link rel="stylesheet" href="{{ asset('assets/css/responsive.css') }}">

<!-- FAVICONS -->
<link rel="icon" href="{{ getSettingImage('app_fav_icon') }}" type="image/png" sizes="16x16">
<link rel="shortcut icon" href="{{ getSettingImage('app_fav_icon') }}" type="image/x-icon">
<link rel="shortcut icon" href="{{ getSettingImage('app_fav_icon') }}">


<!-- Sweetalert & Toastr -->
<link rel="stylesheet" href="{{asset('assets/sweetalert2/sweetalert2.css')}}">
<link rel="stylesheet" href="{{ asset('assets/css/toastr.min.css') }}">
<link rel="stylesheet" href="{{ asset('assets/css/dropify.css') }}">

<!-- Select2 -->
<link href="{{ asset('assets/css/select2.min.css') }}" rel="stylesheet" />
