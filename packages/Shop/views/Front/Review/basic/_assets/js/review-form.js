/**
 * 후기 작성 폼 (basic 스킨) 뷰 스크립트.
 */
const ReviewForm = {
    setRating(value) {
        document.getElementById('ratingInput').value = value;
        document.querySelectorAll('.shop-review-form__star').forEach((star, idx) => {
            star.classList.toggle('active', idx < value);
        });
    },

    previewImage(slot, input) {
        if (!input.files?.[0]) return;
        const reader = new FileReader();
        reader.onload = (e) => {
            const container = document.getElementById(`imgSlot${slot}`);
            container.innerHTML = `
                <img src="${e.target.result}" alt="미리보기">
                <button class="shop-review-form__img-remove" type="button" onclick="ReviewForm.removeImage(${slot})">
                    <i class="bi bi-x"></i>
                </button>`;
        };
        reader.readAsDataURL(input.files[0]);
    },

    removeImage(slot) {
        const container = document.getElementById(`imgSlot${slot}`);
        container.innerHTML = `<i class="bi bi-plus-lg" style="font-size:1.5rem;color:#9ca3af"></i>
            <input type="file" id="imgFile${slot}" name="fileData[image${slot}]" accept="image/*" style="display:none" onchange="ReviewForm.previewImage(${slot}, this)">`;
    }
};

// 초기 별점 5점 표시
ReviewForm.setRating(5);
