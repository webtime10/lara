{{-- Ожидает $languages (коллекция с code). Автоподстановка slug через admin.slug.preview (Str::slug на сервере). --}}
@isset($languages)
@php
    $slugCodes = $languages->map(fn ($l) => strtolower($l->code))->values()->all();
@endphp
<script>
(function () {
    var codes = @json($slugCodes);
    var previewUrl = @json(route('admin.slug.preview'));

    function fetchSlug(text, slugEl) {
        fetch(previewUrl + '?text=' + encodeURIComponent(text || ''), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && typeof data.slug === 'string') {
                    slugEl.value = data.slug;
                }
            })
            .catch(function () {});
    }

    codes.forEach(function (code) {
        var nameEl = document.getElementById('name_' + code);
        var slugEl = document.getElementById('slug_' + code);
        if (!nameEl || !slugEl) {
            return;
        }
        var state = { locked: slugEl.getAttribute('data-slug-locked') === '1' };
        var timer = null;

        nameEl.addEventListener('input', function () {
            if (state.locked) {
                return;
            }
            clearTimeout(timer);
            var self = this;
            timer = setTimeout(function () { fetchSlug(self.value, slugEl); }, 200);
        });

        slugEl.addEventListener('input', function () {
            if (this.value.trim() === '') {
                state.locked = false;
                this.setAttribute('data-slug-locked', '0');
                fetchSlug(nameEl.value, this);
            } else {
                state.locked = true;
                this.setAttribute('data-slug-locked', '1');
            }
        });
    });
})();
</script>
@endisset
