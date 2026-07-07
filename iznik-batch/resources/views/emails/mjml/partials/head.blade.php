<mj-head>
  <mj-preview>{{ $preview ?? '' }}</mj-preview>
  {{-- inline="inline" tells MJML to inline these styles into elements, which is required for Gmail --}}
  <mj-style inline="inline">
    a { color: #338808; text-decoration: none; font-weight: bold }
    ol { margin-top: 0; margin-bottom: 0; padding-left: 2.4em; }
    li { margin: 0.5em 0; }
    @if(!empty($styles)){!! $styles !!}@endif
  </mj-style>
  @if(!empty($mediaStyles))
    {{-- Non-inline block — used for @media queries, which can't be inlined
         onto elements. Lands in a <style> tag in the compiled HTML, which
         most mobile mail apps honour (Gmail mobile, Apple Mail, iOS Mail). --}}
    <mj-style>
      {!! $mediaStyles !!}
    </mj-style>
  @endif
  <mj-attributes>
    {{-- Modern system font stack: San Francisco (Apple), Segoe UI (Windows), Roboto (Android) --}}
    <mj-all font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif"></mj-all>
    {{-- Freegle brand colors from website --}}
    <mj-class name="bg-success" background-color="#338808" />
    <mj-class name="bg-secondary" background-color="#00A1CB" />
    <mj-class name="bg-header" background-color="#1d6607" />
    <mj-class name="bg-warning" background-color="#e38d13" />
    <mj-class name="bg-light" background-color="#f8f9fa" />
    <mj-class name="bg-green-light" background-color="#f0f7e6" />
    <mj-class name="bg-danger" background-color="#dc3545" />
    <mj-class name="text-danger" color="#dc3545" />
    <mj-class name="text-success" color="#338808" />
    <mj-class name="text-header" color="#1d6607" />
    <mj-class name="btn-success" background-color="#338808" color="white" font-weight="bold" />
    <mj-class name="btn-secondary" background-color="#00A1CB" color="white" font-weight="bold" />
    <mj-class name="btn-dark" background-color="#333333" color="white" font-weight="bold" />
    <mj-class name="btn-warning" background-color="#e38d13" color="white" font-weight="bold" />
    {{-- ModTools brand colors --}}
    <mj-class name="bg-modtools" background-color="#396aa3" />
    <mj-class name="bg-modtools-dark" background-color="#2b5280" />
    <mj-class name="text-modtools" color="#396aa3" />
    <mj-class name="btn-modtools" background-color="#396aa3" color="white" font-weight="bold" />
  </mj-attributes>
</mj-head>
