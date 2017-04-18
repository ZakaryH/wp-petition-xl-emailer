jQuery(document).ready( function($) {
  // TODO make the AJAX query dynamic, based on PHP var maybe


  /* ----------------------------------------------- Event handlers -----------------------------------------*/
  // form submission
  jQuery(".rep-petition-form").bind("submit", handleForm);
  // checkbox state change
  jQuery('input[type=checkbox]').each(toggleListCheckbox).change(toggleListCheckbox);


  /*--------------------------------Data Manipulation Functions-----------------------------------------*/

  // validate the postal code according to canadian format (still not necessarily valid code)
  function postalFilter (postalCode) {
      var ca = new RegExp(/([ABCEGHJKLMNPRSTVXY]\d)([ABCEGHJKLMNPRSTVWXYZ]\d){2}/i);

      if (! postalCode) {
          return null;
      }

      postalCode = postalCode.toString().trim();
      postalCode = postalCode.replace(/\s/g, '');


      if (ca.test(postalCode.toString().replace(/\W+/g, ''))) {
          return postalCode;
      }
      return null;
  }

  // add/remove html in the message display area based on checkbox state
  function toggleListCheckbox() {
      var checkboxId = jQuery(this).attr('id');
      var checkMessage = jQuery(this).attr('data-msg');

      if(jQuery(this).is(':checked')) {
          jQuery('.petition-message-display').append('<p class="' + checkboxId  + '">' + checkMessage  + '</p>');
      } else {
          jQuery('.petition-message-display .' + checkboxId).remove();
      }
  }

  /* handle the form logic in JS */
  function handleForm(e) {
    var postalCode = e.srcElement['postal_code'].value;
    var firstName = e.srcElement['first_name'].value;
    var lastName = e.srcElement['last_name'].value;
    var userEmail = e.srcElement['user_email'].value;
    var association = e.srcElement['association'].value;
    // make an array of the checked checkboxes
    var userMessages = jQuery(".rep-petition-form input:checkbox:checked").map(function(){
      return jQuery(this).val();
    }).get();

    if (e.preventDefault) {
      e.preventDefault();
    }

    /* validate, trim and remove spaces*/
    firstName = firstName.trim();
    lastName = lastName.trim();
    postalCode = postalFilter(postalCode);
    if (postalCode === null || postalCode === undefined) {
      showErrors( "Invalid postal code" );
    } else if ( firstName === '' ) {
      showErrors( "Invalid first name" );
    } else if ( lastName === '' ) {
      showErrors( "Invalid last name" );
    } else {
      pxe_plugin_init( postalCode, firstName, lastName, userEmail, userMessages, association );
    }
    return false;
  }

  function showErrors ( errorMessage ) {
    if ( jQuery("#petition-error-div").children().length === 0 ) {
      jQuery("#petition-error-div").append( "<strong class='error'>" + errorMessage + "</strong>" );
    } else {
        jQuery("#petition-error-div .error").replaceWith( "<strong class='error'>" + errorMessage + "</strong>" );
      }
  }

  /*--------------------------------Plugin Functions-----------------------------------------*/

  // pass user input to the plugin, handle the response
  function pxe_plugin_init ( postalCode, firstName, lastName, email, messages, association) {
    var siteUrl = document.getElementById("siteUrl").value;

    jQuery(".load-container").append( "<div class='load-spinner'></div>" );
    jQuery("#rep-petition-form input").prop('disabled', true);
     jQuery.ajax({
      type:'POST',
      data:{
        action:'pxe_main_process_async',
        postalCode: postalCode,
        firstName: firstName,
        lastName: lastName,
        email: email,
        messages: messages,
        association: association
      },
      url: siteUrl + "/wp-admin/admin-ajax.php",
      success: function(value) {
        // console.log(JSON.parse(value) );
        jQuery(".load-spinner").remove();
        showResults( JSON.parse( value ) );
      },
      error: function(error) {
        // display errors
        jQuery("#rep-petition-form input").prop('disabled', false);
        jQuery(".load-spinner").remove();
        showErrors( error.responseText );
      }
    });
  }

  // display the representatives and success message to user
  function showResults (repsData) {
    var display = jQuery("#rep-info-display");

    // hide the form
    jQuery(".rep-petition-form").hide( "slow" );
    // show reps display
    jQuery("#rep-info-display").css( "display", "block" );
    display.append( "<p class='success-message'>Thank you for your support, the email has been sent to your local representatives.  Check your email for contact information for you to followup.</p>" );
    
    if (repsData.other) {
      console.log("Non Edmonton submission");
    } else {
      //handle each rep
      for( var j=0; j<repsData.length; j++ ){
          display.append( "<div class='representative-info rep-" + ( j +1 ) + "'>" );
          var innerDisplay = jQuery( ".rep-" + ( j+1 ) );
          innerDisplay.append( "<div class='rep-photo-container'><img src='" + repsData[j].photo_url + "' class='rep-photo' alt='" + repsData[j].name + "'></div>" );
          innerDisplay.append( "<p class='rep-name'>" + repsData[j].name + "</p>");
          innerDisplay.append( "<p class='rep-position'>" + repsData[j].elected_office + "</p>");
          innerDisplay.append( "<p class='rep-email'>" + repsData[j].email + "</p>");
          display.append( "</div>");
      }
    }
  }
});
