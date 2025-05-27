jQuery(function($){
  // Show filters on search click if enabled (inline slideDown)
  if(pfiAjax.showFiltersOnSearchClick){
    $('.pfi-filters-bar').hide();
    $('.pfi-search-form input[name="pfi_q"]').on('focus click', function(){
      $('.pfi-filters-bar').slideDown(200);
    });
  }
  // Filtre butonuna tıklayınca popup aç
  $('.pfi-filter-btn').on('click', function(){
    var col = $(this).data('filter');
    // All Products: force search_results else branch with pagination
    if (col === 'all') {
      var url = new URL(window.location.href);
      url.searchParams.set('pfi_filter','all');
      url.searchParams.delete('pfi_q');
      url.searchParams.delete('pfi_page');
      window.location = url.toString();
      return;
    }
    $('#pfi-filter-popup').html('<div class="pfi-filter-popup"><div class="pfi-filter-popup-inner"><div class="pfi-popup-title">Loading...</div></div></div>');
    $('#pfi-filter-popup').show();
    $.post(pfiAjax.ajaxurl, {action:'pfi_get_filter_values', column:col}, function(resp){
      if(resp.success){
        var allValues = resp.data;
        window.pfiPopupData = allValues;
        window.pfiPopupCurrentPage = 1;
        window.pfiPopupPerPage = 15;
        // Build minimal popup structure
        var popup = '<div class="pfi-filter-popup"><div class="pfi-filter-popup-inner">';
        popup += '<button class="pfi-popup-close">&times;</button>';
        popup += '<div class="pfi-popup-title">Select '+col.replace(/_/g,' ').replace(/\b\w/g, l => l.toUpperCase())+'</div>';
        popup += '<input type="text" class="pfi-popup-search" placeholder="Search '+col.replace(/_/g,' ')+'..." />';
        popup += '<div class="pfi-popup-list"></div>';
        popup += '<div class="pfi-popup-nav"></div>';
        popup += '</div></div>';
        $('#pfi-filter-popup').html(popup).show();
        function renderList(){
          var allValues = window.pfiPopupData;
          var currentPage = window.pfiPopupCurrentPage;
          var perPage = window.pfiPopupPerPage;
          var term = $('.pfi-popup-search').val().toLowerCase();
          var filtered = allValues.filter(function(v){ return v.toLowerCase().indexOf(term) !== -1; });
          var totalPages = Math.ceil(filtered.length / perPage) || 1;
          if(currentPage < 1) { currentPage = 1; }
          if(currentPage > totalPages) { currentPage = totalPages; }
          var start = (currentPage - 1) * perPage;
          var end = start + perPage;
          var listHtml = '';
          for(var i = start; i < end && i < filtered.length; i++){
            var val = filtered[i].replace(/"/g,'&quot;');
            listHtml += '<button class="pfi-popup-list-btn" data-value="'+val+'" data-col="'+col+'">'+filtered[i]+'</button>';
          }
          $('.pfi-popup-list').html(listHtml);
          // Pagination nav
          var navHtml = '';
          if(currentPage > 1) { navHtml += '<button class="pfi-popup-prev"><i class="fas fa-chevron-left"></i> Prev</button>'; }
          if(currentPage < totalPages) { navHtml += '<button class="pfi-popup-next">Next <i class="fas fa-chevron-right"></i></button>'; }
          $('.pfi-popup-nav').html(navHtml);
          window.pfiPopupCurrentPage = currentPage;
        }
        // Initial render
        renderList();
        window.pfiPopupRender = renderList;
      }
    });
  });
  // Popup kapama
  $(document).on('click','.pfi-popup-close',function(){
    $('#pfi-filter-popup').hide().html('');
  });
  // Popup search filter
  $(document).on('input', '.pfi-popup-search', function(){ if(window.pfiPopupRender) window.pfiPopupRender(); });
  // Popup pagination
  $(document).on('click', '.pfi-popup-prev', function(){ if(window.pfiPopupRender){ window.pfiPopupCurrentPage--; window.pfiPopupRender(); }});
  $(document).on('click', '.pfi-popup-next', function(){ if(window.pfiPopupRender){ window.pfiPopupCurrentPage++; window.pfiPopupRender(); }});
  // Filtre seçimi: pfi_filter ve pfi_q ile yönlendir
  $(document).on('click','.pfi-popup-list-btn',function(){
    var val = $(this).data('value');
    var col = $(this).data('col');
    $('#pfi-filter-popup').hide().html('');
    var url = new URL(window.location.href);
    url.searchParams.set('pfi_filter', col);
    url.searchParams.set('pfi_q', val);
    url.searchParams.delete('pfi_page'); // sayfa sıfırlansın
    window.location = url.toString();
  });
});

// Fullscreen modal for product details
jQuery(function($){
  if (pfiAjax.cardClickable) {
    // Open modal on result click
    $(document).on('click','.pfi-results li',function(){
      var content = $(this).html();
      var modal = '<div class="pfi-modal-overlay"><div class="pfi-modal-content"><button class="pfi-modal-close">&times;</button><div class="pfi-modal-body">'+content+'</div></div></div>';
      $('body').append(modal);
    });
    // Close modal on close button
    $(document).on('click','.pfi-modal-close',function(){
      $('.pfi-modal-overlay').remove();
    });
    // Close modal when clicking outside content
    $(document).on('click','.pfi-modal-overlay',function(e){
      if ($(e.target).is('.pfi-modal-overlay')) {
        $('.pfi-modal-overlay').remove();
      }
    });
  }
});

// Search autocomplete suggestions and loading animation
jQuery(function($){
  // Create suggestions dropdown
  var $input = $('.pfi-search-form input[name="pfi_q"]');
  var $suggestions = $('<ul class="pfi-suggestions"></ul>').css({
    position: 'absolute',
    background: '#fff',
    border: '1px solid #ccc',
    display: 'none',
    'list-style': 'none',
    padding: 0,
    margin: 0,
    width: 'auto',
    'max-width': $input.outerWidth() + 'px'
  });
  $input.after($suggestions);
  // Position suggestions
  $suggestions.css({top: $input.position().top + $input.outerHeight(), left: $input.position().left});
  // Input event
  $input.on('input', function(){
    var term = $(this).val();
    if(term.length >= 3) {
      // Show loading indicator
      $('#pfi-loading-overlay').remove();
      $("body").append('<div id="pfi-loading-overlay"><i class="fas fa-spinner fa-spin fa-2x"></i></div>');
      $.post(pfiAjax.ajaxurl, {action:'pfi_suggest', term: term}, function(resp){
        $('#pfi-loading-overlay').remove();
        if(resp.success){
          $suggestions.empty();
          resp.data.forEach(function(item){
            $suggestions.append('<li class="pfi-suggestion-item">'+item+'</li>');
          });
          $suggestions.show();
        }
      });
    } else {
      $suggestions.hide();
    }
  });
  // Click suggestion
  $(document).on('click', '.pfi-suggestion-item', function(){
    var text = $(this).text();
    $input.val(text);
    $suggestions.hide();
    $('.pfi-search-form').submit();
  });
  // Hide on outside click
  $(document).on('click', function(e){
    if(!$(e.target).closest('.pfi-search-form, .pfi-suggestions').length){
      $suggestions.hide();
    }
  });
  // Loading overlay CSS
  $('head').append('<style>#pfi-loading-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,0.8);display:flex;align-items:center;justify-content:center;z-index:10000;} </style>');
}); 